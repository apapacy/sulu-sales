<?php
/*
 * This file is part of the Sulu CMS.
 *
 * (c) MASSIVE ART WebServices GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Bundle\Sales\OrderBundle\Controller;

use FOS\RestBundle\Controller\Annotations\Post;
use FOS\RestBundle\Routing\ClassResourceInterface;
use Hateoas\Representation\CollectionRepresentation;
use JMS\Serializer\SerializationContext;
use Sulu\Bundle\Sales\CoreBundle\Manager\LocaleManager;
use Sulu\Bundle\Sales\OrderBundle\Api\Order;
use Sulu\Bundle\Sales\OrderBundle\Entity\OrderStatus;
use Sulu\Bundle\Sales\OrderBundle\Entity\OrderType;
use Sulu\Bundle\Sales\OrderBundle\Order\Exception\MissingOrderAttributeException;
use Sulu\Bundle\Sales\OrderBundle\Order\Exception\OrderDependencyNotFoundException;
use Sulu\Bundle\Sales\OrderBundle\Order\Exception\OrderException;
use Sulu\Bundle\Sales\OrderBundle\Order\Exception\OrderNotFoundException;
use Sulu\Bundle\Sales\OrderBundle\Order\OrderDependencyManager;
use Sulu\Bundle\Sales\OrderBundle\Order\OrderManager;
use Sulu\Component\Rest\Exception\EntityNotFoundException;
use Sulu\Component\Rest\Exception\MissingArgumentException;
use Sulu\Component\Rest\Exception\RestException;
use Sulu\Component\Rest\ListBuilder\Doctrine\DoctrineListBuilder;
use Sulu\Component\Rest\ListBuilder\Doctrine\DoctrineListBuilderFactory;
use Sulu\Component\Rest\ListBuilder\Doctrine\FieldDescriptor\DoctrineFieldDescriptor;
use Sulu\Component\Rest\ListBuilder\Doctrine\FieldDescriptor\DoctrineJoinDescriptor;
use Sulu\Component\Rest\ListBuilder\ListRepresentation;
use Sulu\Component\Rest\RestController;
use Sulu\Component\Rest\RestHelperInterface;
use Sulu\Component\Security\SecuredControllerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class OrderController extends RestController implements ClassResourceInterface, SecuredControllerInterface
{
    protected static $orderStatusEntityName = 'SuluSalesOrderBundle:OrderStatus';

    protected static $entityKey = 'orders';

    /**
     * Returns all fields that can be used by list.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function fieldsAction(Request $request)
    {
        $locale = $this->getLocaleManager()->retrieveLocale($this->getUser(), $request->get('locale'));

        // Default contacts list
        return $this->handleView($this->view(array_values($this->getManager()->getFieldDescriptors($locale)), 200));
    }

    /**
     * @param Request $request
     *
     * @return Response
     */
    public function cgetAction(Request $request)
    {
        $filter = array();

        $locale = $this->getLocaleManager()->retrieveLocale($this->getUser(), $request->get('locale'));

        $status = $request->get('status');
        if ($status) {
            $filter['status'] = $status;
        }

        if ($request->get('flat') == 'true') {
            /** @var RestHelperInterface $restHelper */
            $restHelper = $this->get('sulu_core.doctrine_rest_helper');

            /** @var DoctrineListBuilderFactory $factory */
            $factory = $this->get('sulu_core.doctrine_list_builder_factory');

            /** @var DoctrineListBuilder $listBuilder */
            $listBuilder = $factory->create($this->getOrderEntityName());

            $restHelper->initializeListBuilder($listBuilder, $this->getManager()->getFieldDescriptors($locale));

            foreach ($filter as $key => $value) {
                $listBuilder->where($this->getManager()->getFieldDescriptor($key), $value);
            }

            // Exclude in cart orders
            $listBuilder->whereNot($this->getStatusFieldDescriptor(), OrderStatus::STATUS_IN_CART);

            $listBuilder->sort(
                $this->getManager()->getFieldDescriptor(
                    'created',
                    $this->getLocaleManager()->retrieveLocale($this->getUser(), $request->get('locale'))),
                'DESC'
            );

            $list = new ListRepresentation(
                $listBuilder->execute(),
                self::$entityKey,
                'get_orders',
                $request->query->all(),
                $listBuilder->getCurrentPage(),
                $listBuilder->getLimit(),
                $listBuilder->count()
            );
        } else {
            $list = new CollectionRepresentation(
                $this->getManager()->findAllByLocale(
                    $this->getLocaleManager()->retrieveLocale($this->getUser(), $request->get('locale')),
                    $filter
                ),
                self::$entityKey
            );
        }

        $view = $this->view($list, 200);

        return $this->handleView($view);
    }

    /**
     * Retrieves and shows an order with the given ID.
     *
     * @param Request $request
     * @param int $id
     *
     * @return Response
     */
    public function getAction(Request $request, $id)
    {
        $locale = $this->getLocaleManager()->retrieveLocale($this->getUser(), $request->get('locale'));
        $view = $this->responseGetById(
            $id,
            function ($id) use ($locale) {
                /** @var Order $order */
                $order = $this->getManager()->findByIdAndLocale($id, $locale);

                // if order was found
                if ($order) {
                    $order->setWorkflows($this->getDependencyManager()->getWorkflows($order));
                    $order->setAllowDelete($this->getDependencyManager()->allowDelete($order));
                }

                return $order;
            }
        );

        $view->setSerializationContext(
            SerializationContext::create()->setGroups(
                ['Default', 'partialCategory']
            )
        );

        return $this->handleView($view);
    }

    /**
     * Creates and stores a new order.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function postAction(Request $request)
    {
        try {
            $data = $request->request->all();
            $data['type'] = OrderType::MANUAL;

            $order = $this->getManager()->save(
                $data,
                $this->getLocaleManager()->retrieveLocale($this->getUser(), $request->get('locale')),
                $this->getUser()->getId()
            );

            $view = $this->view($order, 200);
        } catch (OrderDependencyNotFoundException $exc) {
            $exception = new EntityNotFoundException($exc->getEntityName(), $exc->getId());
            $view = $this->view($exception->toArray(), 400);
        } catch (MissingOrderAttributeException $exc) {
            $exception = new MissingArgumentException($this->getOrderEntityName(), $exc->getAttribute());
            $view = $this->view($exception->toArray(), 400);
        }

        return $this->handleView($view);
    }

    /**
     * Change a order.
     *
     * @param Request $request
     * @param int $id
     *
     * @return Response
     */
    public function putAction(Request $request, $id)
    {
        try {
            $order = $this->getManager()->save(
                $request->request->all(),
                $this->getLocaleManager()->retrieveLocale($this->getUser(), $request->get('locale')),
                $this->getUser()->getId(),
                $id
            );

            $view = $this->view($order, 200);
        } catch (OrderNotFoundException $exc) {
            $exception = new EntityNotFoundException($exc->getEntityName(), $exc->getId());
            $view = $this->view($exception->toArray(), 404);
        } catch (OrderDependencyNotFoundException $exc) {
            $exception = new EntityNotFoundException($exc->getEntityName(), $exc->getId());
            $view = $this->view($exception->toArray(), 400);
        } catch (MissingOrderAttributeException $exc) {
            $exception = new MissingArgumentException($this->getOrderEntityName(), $exc->getAttribute());
            $view = $this->view($exception->toArray(), 400);
        } catch (OrderException $exc) {
            $exception = new RestException($exc->getMessage());
            $view = $this->view($exception->toArray(), 400);
        }

        return $this->handleView($view);
    }

    /**
     * Triggers actions like status conversion.
     *
     * @Post("/orders/{id}")
     *
     * @param $id
     * @param Request $request
     *
     * @throws EntityNotFoundException
     * @throws RestException
     *
     * @return Response
     */
    public function postTriggerAction($id, Request $request)
    {
        $status = $request->get('action');
        $em = $this->getDoctrine()->getManager();

        try {
            $order = $this->getManager()->findByIdAndLocale(
                $id,
                $this->getLocaleManager()->retrieveLocale($this->getUser(), $request->get('locale'))
            );
            if (!$order) {
                throw new OrderNotFoundException($id);
            }

            switch ($status) {
                case 'confirm':
                    $this->getManager()->convertStatus($order, OrderStatus::STATUS_CONFIRMED);
                    break;
                case 'edit':
                    $this->getManager()->convertStatus($order, OrderStatus::STATUS_CREATED);
                    break;
                default:
                    throw new RestException("Unrecognized status: " . $status);
            }

            $em->flush();
            $view = $this->view($order, 200);
        } catch (OrderNotFoundException $exc) {
            $exception = new EntityNotFoundException($exc->getEntityName(), $exc->getId());
            $view = $this->view($exception->toArray(), 404);
        }

        return $this->handleView($view);
    }

    /**
     * Delete an order with the given id.
     *
     * @param int $id
     *
     * @return Response
     */
    public function deleteAction($id)
    {
        $delete = function ($id) {
            $this->getManager()->delete($id);
        };
        $view = $this->responseDelete($id, $delete);

        return $this->handleView($view);
    }

    /**
     * Returns name of order entity.
     *
     * @return string
     */
    protected function getOrderEntityName()
    {
        return $this->container->getParameter('sulu.model.sales_order.class');
    }

    /**
     * Holds status field descriptor (which is not needed in list).
     *
     * @return DoctrineFieldDescriptor
     */
    private function getStatusFieldDescriptor()
    {
        return new DoctrineFieldDescriptor(
            'id',
            'status_id',
            self::$orderStatusEntityName,
            'salesorder.orders.status',
            array(
                self::$orderStatusEntityName => new DoctrineJoinDescriptor(
                    self::$orderStatusEntityName,
                    $this->getOrderEntityName() . '.status'
                ),
            )
        );
    }

    /**
     * @return OrderManager
     */
    private function getManager()
    {
        return $this->get('sulu_sales_order.order_manager');
    }

    /**
     * @return OrderDependencyManager
     */
    private function getDependencyManager()
    {
        return $this->get('sulu_sales_order.order_dependency_manager');
    }

    /**
     * @return LocaleManager
     */
    private function getLocaleManager()
    {
        return $this->get('sulu_sales_core.locale_manager');
    }

    /**
     * {@inheritDoc}
     */
    public function getSecurityContext()
    {
        return 'sulu.sales_order.orders';
    }
}
