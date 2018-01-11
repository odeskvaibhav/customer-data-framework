pimcore.registerNS("pimcore.plugin.customermanagementframework");

pimcore.plugin.customermanagementframework = Class.create(pimcore.plugin.admin, {
    getClassName: function () {
        return "pimcore.plugin.customermanagementframework";
    },

    initialize: function () {
        pimcore.plugin.broker.registerPlugin(this);

    },

    pimcoreReady: function (params, broker) {
        // alert("CustomerManagementFramework Plugin Ready!");

        this.initToolbar();
        this.initNewsletterQueueInfo();
    },

    initToolbar: function () {
        var toolbar = pimcore.globalmanager.get('layout_toolbar');
        var user = pimcore.globalmanager.get('user');

        var menuItems = toolbar.cmfMenu;
        if (!menuItems) {
            menuItems = new Ext.menu.Menu({cls: 'pimcore_navigation_flyout'});
            toolbar.cmfMenu = menuItems;
        }

        // customer view
        if (user.isAllowed('plugin_cmf_perm_customerview')) {
            var customerViewPanelId = 'plugin_cmf_customerview';

            var menuOptions = pimcore.settings.cmf.shortcutFilterDefinitions.length ? {
                cls: "pimcore_navigation_flyout",
                shadow: false,
                items: []
            } : null;

            var customerMenu = Ext.create('Ext.menu.Item', {
                text: t('plugin_cmf_customerview'),
                iconCls: 'pimcore_icon_customers',
                hideOnClick: false,
                menu: menuOptions,
                handler: function () {
                    try {
                        pimcore.globalmanager.get(customerViewPanelId).activate();
                    }
                    catch (e) {
                        pimcore.globalmanager.add(
                            customerViewPanelId,
                            new pimcore.tool.genericiframewindow(
                                customerViewPanelId,
                                '/admin/customermanagementframework/customers/list',
                                'pimcore_icon_customers',
                                t('plugin_cmf_customerview')
                            )
                        );
                    }
                }
            });

            // add to menu
            menuItems.add(customerMenu);

            $(pimcore.settings.cmf.shortcutFilterDefinitions).each(function(){
                var filterId = this.id;
                var filterKey = 'plugin_cmf_customerview_filter_' + this.id;
                var filterName = this.name;
                var filterItem = {
                    text: filterName,
                    iconCls: 'pimcore_icon_customers',
                    handler: function () {
                        try {
                            pimcore.globalmanager.get(filterKey).activate();
                        }
                        catch (e) {
                            pimcore.globalmanager.add(
                                filterKey,
                                new pimcore.tool.genericiframewindow(
                                    filterKey,
                                    '/admin/customermanagementframework/customers/list?filterDefinition[id]=' + filterId,
                                    'pimcore_icon_customers',
                                    filterName
                                )
                            );
                        }
                    }
                };
                customerMenu.getMenu().add(filterItem);
            });
        }

        // customer duplicates view
        if (pimcore.settings.cmf.duplicatesViewEnabled && user.isAllowed('plugin_cmf_perm_customerview')) {
            var customerDuplicateViewPanelId = 'plugin_cmf_customerduplicatesview';
            var item = {
                text: t('plugin_cmf_customerduplicatesview'),
                iconCls: 'pimcore_icon_customerduplicates',
                handler: function () {
                    try {
                        pimcore.globalmanager.get(customerDuplicateViewPanelId).activate();
                    }
                    catch (e) {
                        pimcore.globalmanager.add(
                            customerDuplicateViewPanelId,
                            new pimcore.tool.genericiframewindow(
                                customerDuplicateViewPanelId,
                                '/admin/customermanagementframework/duplicates/list',
                                'pimcore_icon_customerduplicates',
                                t('plugin_cmf_customerduplicatesview')
                            )
                        );
                    }
                }
            };

            // add to menu
            menuItems.add(item);
        }

        if (user.isAllowed('plugin_cmf_perm_customer_automation_rules')) {
            var customerAutomationRulesPanelId = 'plugin_cmf_customerautomationrules';
            var item = {
                text: t('plugin_cmf_customerautomationrules'),
                iconCls: 'pimcore_icon_customerautomationrules',
                handler: function () {
                    try {
                        pimcore.globalmanager.get(customerAutomationRulesPanelId).activate();
                    }
                    catch (e) {
                        pimcore.globalmanager.add(customerAutomationRulesPanelId, new pimcore.plugin.cmf.config.panel(customerAutomationRulesPanelId));
                    }
                }
            };

            menuItems.add(item);
        }

        if (pimcore.settings.cmf.newsletterSyncEnabled && user.isAllowed('plugin_cmf_perm_newsletter_enqueue_all_customers')) {
            var item = {
                text: t('plugin_cmf_newsletter_enqueue_all_customers'),
                iconCls: 'pimcore_icon_newsletter_enqueue_all_customers',
                handler: function () {
                    Ext.Ajax.request({
                        url: "/webservice/cmf/newsletter/enqueue-all-customers",
                        success: function () {
                            setTimeout(function () {
                                this.checkNewsletterQueueStatus(Ext.get('pimcore_bundle_customerManagementFramework_newsletter_queue_status'));
                            }.bind(this), 3000)
                        }.bind(this)
                    });
                }.bind(this)
            };

            menuItems.add(item);
        }

        // add main menu
        if (menuItems.items.length > 0) {
            var insertPoint = Ext.get('pimcore_menu_settings');
            if (!insertPoint) {
                var dom = Ext.dom.Query.select('#pimcore_navigation ul li:last');
                insertPoint = Ext.get(dom[0]);
            }

            this.navEl = Ext.get(
                insertPoint.insertHtml(
                    'afterEnd',
                    '<li id="pimcore_menu_cmf" class="pimcore_menu_item compatibility" data-menu-tooltip="' + t('plugin_cmf_mainmenu') + '"><svg version="1.1" id="Layer_1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" x="0px" y="0px" width="512px" height="512px" viewBox="0 0 512 512" enable-background="new 0 0 512 512" xml:space="preserve"> <g> <path d="M338,381.996h-26c-28.719,0-52-23.281-52-52v-16.719c11.5-13.656,19.75-29.844,24.875-46.906 c0.531-2.875,3.313-4.313,5.156-6.313c9.969-9.938,11.906-26.734,4.438-38.703c-1-1.813-2.844-3.375-2.719-5.625 c0-15.25,0.063-30.531-0.031-45.766c-0.406-18.391-5.656-37.516-18.563-51.141c-10.406-11.016-24.719-17.563-39.469-20.359 c-18.625-3.563-38.125-3.375-56.594,1.313c-15.969,4.047-31.031,13.406-40.313,27.297c-8.219,12.078-11.844,26.734-12.438,41.188 c-0.219,15.516-0.063,31.063-0.094,46.609c0.344,3.109-2.313,5.219-3.5,7.797c-7.031,12.766-3.938,30.141,7.375,39.422 c2.875,1.969,3.406,5.594,4.438,8.719c4.938,15.344,13.125,29.563,23.438,41.938v17.25c0,28.719-23.281,52-52,52H78 c0,0-47.125,13-78,78v26c0,14.375,11.625,26,26,26h364c14.375,0,26-11.625,26-26v-26C385.125,394.996,338,381.996,338,381.996z"/> <path d="M446,241.996h-22c-24.313,0-44-19.688-44-44V183.84c9.719-11.547,16.719-25.234,21.063-39.688 c0.438-2.438,2.781-3.641,4.344-5.328c8.438-8.422,10.094-22.625,3.75-32.75c-0.844-1.531-2.406-2.859-2.281-4.766 c0-12.906,0.031-25.828-0.031-38.719c-0.344-15.563-4.781-31.75-15.719-43.281C382.313,9.996,370.219,4.449,357.75,2.09 c-15.781-3.016-32.281-2.859-47.906,1.109c-13.531,3.422-26.219,11.344-34.094,23.094c-7,10.219-10,22.625-10.531,34.844 c-0.094,4.703-0.031,9.422-0.031,14.141c11.938,5.344,22.625,12.453,31.188,21.547c17,17.922,26.688,43.656,27.344,72.938 c0.063,10.969,0.063,21.953,0.031,32.938v5.422c12.906,24.266,8.563,54.969-10.188,73.625c0,0.016,0,0.016-0.031,0.016 c-5.281,15.516-12.469,29.734-21.531,42.422v5.813c0,11.031,8.969,20,20,20h26h4.344l4.156,1.156 c0.438,0.125,1.688,0.531,2.625,0.844H490c12.156,0,22-9.844,22-22v-22C485.875,252.996,446,241.996,446,241.996z"/> </g> </svg><div class="custom_menu_tile">Customers</div></li>'
                )
            );

            this.navEl.on('mousedown', toolbar.showSubMenu.bind(menuItems));
        }
    },

    postOpenObject: function (object, type) {
        if ("object" === type && object.data.general.o_className === pimcore.settings.cmf.customerClassName && pimcore.globalmanager.get("user").isAllowed(ActivityView.config.PERMISSION)) {
            var panel = new ActivityView.ActivityTab(object, type).getPanel();

            object.tab.items.items[1].insert(1, panel);
            panel.updateLayout();
        } else if ("object" === type && object.data.general.o_className === "CustomerSegment" && pimcore.globalmanager.get("user").isAllowed(CustomerView.config.PERMISSION)) {
            var panel = new CustomerView.CustomerTab(object, type).getPanel();

            object.tab.items.items[1].insert(1, panel);
            panel.updateLayout();
        }

        this.addSegmentAssignmentTab(object, 'object', type);
    },

    pluginObjectMergerPostMerge: function (data) {
        var frame = document.getElementById("pimcore_iframe_frame_plugin_cmf_customerduplicatesview");
        if (frame) {
            var $ = frame.contentWindow.$;

            $('#customerduplicates_' + data.sourceId + '_' + data.targetId).remove();
            $('#customerduplicates_' + data.targetId + '_' + data.sourceId).remove();

            if (!$('.js-duplicates-item').length) {
                frame.contentWindow.location.reload();
            }
        }
    },

    checkNewsletterQueueStatus: function (statusIcon, initTimeout) {
        Ext.Ajax.request({
            url: "/webservice/cmf/newsletter/get-queue-size",
            method: "get",
            success: function (response) {
                var rdata = Ext.decode(response.responseText);

                document.getElementById('pimcore_bundle_customerManagementFramework_newsletter_queue_status_count').innerHTML = rdata.size;

                if (rdata.size > 0) {
                    statusIcon.show();
                } else {
                    statusIcon.hide();
                }


                if (initTimeout !== false) {
                    setTimeout(this.checkNewsletterQueueStatus.bind(this, statusIcon), 15000);
                }


            }.bind(this)
        });
    },

    initNewsletterQueueInfo: function () {

        if (!pimcore.settings.cmf.newsletterSyncEnabled) {
            return;
        }

        //adding status icon
        var statusBar = Ext.get("pimcore_status");

        var statusIcon = Ext.get(statusBar.insertHtml('afterBegin',
            '<div id="pimcore_bundle_customerManagementFramework_newsletter_queue_status" style="display:none;" data-menu-tooltip="'
            + t("plugin_cmf_newsletter_queue_running_tooltip") + '"><span id="pimcore_bundle_customerManagementFramework_newsletter_queue_status_count"></span></div>'));

        pimcore.helpers.initMenuTooltips();

        this.checkNewsletterQueueStatus(statusIcon);
    },
    postOpenDocument: function (document, type) {

        if (pimcore.settings.cmf.newsletterSyncEnabled && type === 'email') {
            document.tab.items.items[0].add({
                text: t('plugin_cmf_newsletter_export_template'),
                iconCls: 'plugin_cmf_icon_export_action',
                scale: 'small',
                handler: function (obj) {

                    Ext.Ajax.request({
                        url: "/admin/customermanagementframework/templates/export",
                        method: "post",
                        params: {document_id: document.id},
                        success: function (response) {

                            var rdata = Ext.decode(response.responseText);
                            if (rdata && rdata.success) {
                                pimcore.helpers.showNotification(t("success"), t("plugin_cmf_newsletter_export_template_success"), "success");
                            } else {
                                pimcore.helpers.showNotification(t("error"), t("plugin_cmf_newsletter_export_template_error"), "error", response.responseText);
                            }

                        }.bind(this)
                    });

                }.bind(this, document)
            });
            pimcore.layout.refresh();


        }

        this.addSegmentAssignmentTab(document, 'document', type);
    },

    postOpenAsset: function (asset, type) {
        this.addSegmentAssignmentTab(asset, 'asset', type);
    },

    addSegmentAssignmentTab: function (element, type, subType) {
        var addTab = Boolean(pimcore.settings.cmf.segmentAssignment[type][subType]);

        if('object' === type && 'folder' !== subType) {
            addTab &= pimcore.settings.cmf.segmentAssignment[type][subType][element.data.general.o_className];
        }

        if (!addTab) {
            return;
        }

        this.segmentTab = new pimcore.plugin.customermanagementframework.segmentAssignmentTab(element, type);
        var tabPanel = element.tab.items.items[1];
        tabPanel.insert(tabPanel.items.length, this.segmentTab.getLayout());
        tabPanel.updateLayout();
    }
});

var customermanagementframeworkPlugin = new pimcore.plugin.customermanagementframework();

