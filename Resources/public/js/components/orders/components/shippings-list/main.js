/*
* This file is part of the Sulu CMS.
*
* (c) MASSIVE ART WebServices GmbH
*
* This source file is subject to the MIT license that is bundled
* with this source code in the file LICENSE.
*/

define(['app-config'], function(AppConfig) {

    'use strict';

    var bindCustomEvents = function() {
        // delete clicked
        this.sandbox.on('sulu.list-toolbar.delete', function() {
            this.sandbox.emit('husky.datagrid.items.get-selected', function(ids) {
                this.sandbox.emit('sulu.salesshipping.shipping.delete', ids);
            }.bind(this));
        }, this);

        // add clicked
        this.sandbox.on('sulu.list-toolbar.add', function() {
            this.sandbox.emit('sulu.salesshipping.shipping.new', this.orderId);
        }, this);

        // back to list
        this.sandbox.on('sulu.header.back', function() {
            this.sandbox.emit('sulu.salesshipping.orders.list');
        }, this);
    };

    return {
        view: true,

        layout: {
            sidebar: {
                width: 'fixed',
                cssClasses: 'sidebar-padding-50'
            }
        },

        templates: ['/admin/shipping/template/shipping/list'],

        initialize: function() {
            this.orderId = null;

            this.render();
            bindCustomEvents.call(this);

            this.initSidebar();

        },

        initSidebar: function() {

            var link = '/admin/widget-groups/order-detail{?params*}',
                data = this.options.data,
                url, uriTemplate;

            if(!!data.contact && !!data.account && !!data.status){
                uriTemplate = this.sandbox.uritemplate.parse(link);
                url = uriTemplate.expand({
                    params: {
                        contact: data.contact.id,
                        account: data.account.id,
                        status: data.status.status,
                        locale: AppConfig.getUser().locale,
                        orderDate: data.orderDate,
                        orderNumber: data.number,
                        orderId: data.id
                    }
                });

                this.sandbox.emit('sulu.sidebar.set-widget', url);
            } else {
                this.sandbox.logger.error('required values for sidebar not present!');
            }
        },

        render: function() {
            this.orderId = this.options.data.id;

            this.sandbox.dom.html(this.$el, this.renderTemplate('/admin/shipping/template/shipping/list'));

            // init list-toolbar and datagrid
            this.sandbox.sulu.initListToolbarAndList.call(this, 'orderShippingFields', '/admin/api/shippings/fields?context=order',
                {
                    el: this.$find('#list-toolbar-container'),
                    instanceName: 'shippings',
                    inHeader: true,
                    template: 'default'
                },
                {
                    el: this.sandbox.dom.find('#shippings-list', this.$el),
                    url: '/admin/api/shippings?flat=true&orderId=' + this.orderId,
                    searchInstanceName: 'shippings',
                    searchFields: ['fullName'],
                    resultKey: 'shippings',
                    viewOptions: {
                        table: {
                            icons: [
                                {
                                    icon: 'pencil',
                                    column: 'number',
                                    align: 'left',
                                    callback: function(id) {
                                        this.sandbox.emit('sulu.salesshipping.shipping.load', id, this.orderId);
                                    }.bind(this)
                                }
                            ],
                            highlightSelected: true,
                            fullWidth: false
                        }
                    }
                }
            );
        }
    };
});
