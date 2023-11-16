Ext.define('Netresearch.widget.Admin', {
    extend: 'Ext.tab.Panel',

    requires: [
        'Netresearch.store.AdminCustomers',
        'Netresearch.store.AdminProjects',
        'Netresearch.store.AdminTeams',
        'Netresearch.store.AdminUsers',
        'Netresearch.store.AdminPresets',
        'Netresearch.store.TicketSystems',
        'Netresearch.store.AdminContracts'
    ],

    /* Load all necessary stores */
    customerStore: Ext.create('Netresearch.store.AdminCustomers'),
    projectStore: Ext.create('Netresearch.store.AdminProjects'),
    userStore: Ext.create('Netresearch.store.AdminUsers'),
    teamStore: Ext.create('Netresearch.store.AdminTeams'),
    ticketSystemStore: Ext.create('Netresearch.store.TicketSystems'),
    activityStore: Ext.create('Netresearch.store.Activities'),
    presetStore: Ext.create('Netresearch.store.AdminPresets'),
    contractStore: Ext.create('Netresearch.store.AdminContracts'),

    /* Strings */
    _tabTitle: 'Administration',
    _nameTitle: 'Name',
    _teamsTitle: 'Teams',
    _activeTitle: 'Active',
    _globalTitle: 'Global',
    _addCustomerTitle: 'Add customer',
    _editTitle: 'Edit',
    _editCustomerTitle: 'Edit customer',
    _forAllTeamsTitle: 'for all teams',
    _saveTitle: 'Save',
    _deleteTitle: 'Delete',
    _seriousErrorTitle: 'A serious error occurred. Find more details in Firebug or the Chrome Developer Tools.',
    _customerTitle: 'Customer',
    _ticketPrefixTitle: 'Ticket prefix',
    _ticketPrefixTitleHelp: 'Multiple may be separated with commas',
    _ticketNumberTitle: 'Ticket number',
    _ticketNumberTitleHelp: 'Instead of the ticket prefix. Tasks in Epics and subtasks are taken into account. Separate multiple with a comma.',
    _ticketSystemTitle: 'Ticket system',
    _internalJiraTicketSystem: 'internal JIRA Ticket-System',
    _projectTitle: 'Project',
    _addProjectTitle: 'Add project',
    _editProjectTitle: 'Edit project',
    _projectSubticketsTitle: 'Known subtickets',
    _projectSubticketsSyncTitle: 'Sync subtickets',
    _subticketSyncFinishedTitle: 'Subtickets have been synchronized from Jira.',
    _forAllCustomersTitle: 'for all customers',
    _userNameTitle: 'User name',
    _abbreviationTitle: 'Abbr',
    _typeTitle: 'Type',
    _addUserTitle: 'Add user',
    _editUserTitle: 'Edit user',
    _languageTitle: 'Language',
    _developerTitle: 'Developer',
    _projectManagerTitle: 'Project manager',
    _controllingTitle: 'Controlling',
    _teamTitle: 'Team',
    _teamLeadTitle: 'Team lead',
    _addTeamTitle: 'Add team',
    _editTeamTitle: 'Edit team',
    _teamSavedTitle: 'The team has been successfully saved.',
    _customerManagementTitle: 'Customer management',
    _projectManagementTitle: 'Project management',
    _userManagementTitle: 'User management',
    _teamManagementTitle: 'Team management',
    _presetManagementTitle: 'Preset management',
    _presetSavedTitle: 'The preset has been successfully saved.',
    _addPresetTitle: 'Add preset',
    _editPresetTitle: 'Edit preset',
    _activityTitle: 'Activity',
    _descriptionTitle: 'Description',
    _ticketSystemManagementTitle: 'Ticket system management',
    _ticketSystemSavedTitle: 'The ticket system has been successfully saved.',
    _urlTitle: 'URL',
    _ticketUrlTitle: 'Ticket URL',
    _ticketUrlHint: '"%s" as placeholder for ticket name',
    _timebookingTitle: 'Time booking',
    _loginTitle: 'Login',
    _passwordTitle: 'Password',
    _publicKeyTitle: 'Public key',
    _privateKeyTitle: 'Private key',
    _addTicketSystemTitle: 'Add ticket system',
    _errorsTitle: 'Errors',
    _errorTitle: 'Error',
    _successTitle: 'Success',
    _estimationTitle: 'Estimated Duration',
    _internalJiraProjectKey: 'internal JIRA Project Key',
    _offerTitle: 'Offer',
    _billingTitle: 'Billing',
    _costCenterTitle: 'Cost Center',
    _projectLeadTitle: 'Project Lead',
    _technicalLeadTitle: 'Technical Lead',
    _max31CharactersTitle: 'At maximum 31 characters are allowed here',
    _activityManagementTitle: 'Activity management',
    _addActivityTitle: 'Add activity',
    _editActivityTitle: 'Edit activity',
    _activitySavedTitle: 'The activity has been successfully saved.',
    _needsTicketTitle: 'Needs ticket',
    _factorTitle: 'Factor',

    _contractManagementTitle: 'Contracts',
    _addContractTitle: 'Add contract',
    _editContractTitle: 'Edit contract',
    _contractSavedTitle: 'Contact saved',
    _startDateTitle: 'Start date',
    _endDateTitle: 'End date',

    _oauthConsumerKeyTitle: 'OAuth consumer key',
    _oauthConsumerSecretTitle: 'OAuth consumer secret',
    _refreshTitle: 'Refresh',
    _startTitle: 'Start',
    _endTitle: 'End',
    _userTitle: 'User',
    _hours0Title: 'Sunday (h)',
    _hours1Title: 'Monday (h)',
    _hours2Title: 'Tuesday (h)',
    _hours3Title: 'Wednesday (h)',
    _hours4Title: 'Thursday (h)',
    _hours5Title: 'Friday (h)',
    _hours6Title: 'Saturday (h)',

    initComponent: function () {
        this.on('render', this.refreshStores, this);

        var panel = this;

        var billingStore = new Ext.data.ArrayStore({
            fields: ['value', 'displayname'],
            data: [
                [0, 'None'],
                [1, 'Time And Material'],
                [2, 'Fixed Price']
            ]
        });

        var customerGrid = Ext.create('Ext.grid.Panel', {
            store: this.customerStore,
            teamStore: this.teamStore,
            columns: [
                {
                    header: 'Id',
                    dataIndex: 'id',
                    hidden: true
                }, {
                    header: this._nameTitle,
                    dataIndex: 'name',
                    flex: 1,
                    field: {
                        xtype: 'textfield'
                    }
                },
                {
                    header: this._teamsTitle,
                    dataIndex: 'teams',
                    flex: 1,
                    renderer: function(value) {
                        var output = '';
                        /* Display space separated list of related teams */
                        Ext.each(value, function(teamId) {
                            if (isNaN(teamId)) {
                              return;
                            }
                            var team = customerGrid.teamStore.getById(parseInt(teamId));
                            if (null == team) {
                              return;
                            }
                            if (output != '')
                                output += ', ';
                            output += team.data.name;
                        });
                        return output;
                    }
                },
                {
                    header: this._activeTitle,
                    dataIndex: 'active',
                    field: {
                        xtype: 'checkbox'
                    },
                    renderer: function(value) {
                        return renderCheckbox(value);
                    }
                },
                {
                    header: this._globalTitle,
                    dataIndex: 'global',
                    field: {
                        xtype: 'checkbox'
                    },
                    renderer: function(value) {
                        return renderCheckbox(value);
                    }
                }
            ],
            tbar: [
                {
                    text: this._addCustomerTitle,
                    iconCls: 'icon-add',
                    scope: this,
                    handler: function() {
                        customerGrid.editCustomer();
                    }
                }, {
                    text: this._refreshTitle,
                    iconCls: 'icon-refresh',
                    scope: this,
                    handler: function() {
                        customerGrid.refresh();
                    }
                }
            ],
            listeners: {
                /* Right-click menu */
                itemcontextmenu: function(grid, record, item, index, event, options) {
                    event.stopEvent();

                    var contextMenu = Ext.create('Ext.menu.Menu', {
                        items: [
                            {
                                text: panel._editTitle,
                                iconCls: 'icon-edit',
                                scope: this,
                                handler: function() {
                                    this.editCustomer(record.data);
                                }
                            }, {
                                text: panel._deleteTitle,
                                iconCls: 'icon-delete',
                                scope: this,
                                handler: function() {
                                    this.deleteCustomer(record.data);
                                }
                            }
                        ]
                    });

                    contextMenu.showAt(event.xy);
                }
            },
            editCustomer: function(record) {
                if(!record) record = {};

                var teamStore = Ext.create('Netresearch.store.AdminTeams', {
                    autoLoad: false
                });

                var window = Ext.create('Ext.window.Window', {
                    title: panel._editCustomerTitle,
                    modal: true,
                    width: 400,
                    id: 'edit-customer-window',
                    layout: 'fit',
                    listeners: {
                        /* Reload on destroy */
                        destroy: {
                            scope: this,
                            fn: function() {
                                this.refresh();
                            }
                        }
                    },
                    items: [
                        new Ext.form.Panel({
                            bodyPadding: 5,
                            defaultType: 'textfield',
                            items: [
                                new Ext.form.field.Hidden({
                                    name: 'id',
                                    value: record.id ? record.id : 0
                                }), {
                                    fieldLabel: panel._nameTitle,
                                    name: 'name',
                                    anchor: '100%',
                                    allowBlank: false,
                                    minLength: 3,
                                    value: record.name ? record.name : ''
                                },
                                new Ext.form.field.Checkbox({
                                    fieldLabel: panel._activeTitle,
                                    name: 'active',
                                    inputValue: 1,
                                    checked: record.active ? record.active : 0
                                }),
                                new Ext.form.field.Checkbox({
                                    fieldLabel: panel._globalTitle + '(' + panel._forAllTeamsTitle + ')',
                                    name: 'global',
                                    inputValue: 1,
                                    checked: record.global ? record.global : 0
                                }),
                                new Ext.form.ComboBox({
                                    fieldLabel: panel._teamsTitle,
                                    name: 'teams[]',
                                    store: teamStore,
                                    queryMode: 'local',
                                    displayField: 'name',
                                    valueField: 'id',
                                    anchor: '100%',
                                    typeAhead: true,
                                    multiSelect: true,
                                    triggerAction: 'all',
                                    //disabled: record.global ? true : false,
                                    listeners: {
                                        /* Reload teams column to fit information */
                                        afterrender: function(field, value) {
                                            teamStore.load({
                                                params: {
                                                    team: field.getValue()
                                                }
                                            });
                                        },
                                        select: function(field, value) {
                                            teamStore.load({
                                                params: {
                                                    team: field.getValue()
                                                }
                                            });
                                        }
                                    },
                                    value: record.teams ? record.teams : []
                                })
                            ],
                            buttons: [
                                {
                                    text: panel._saveTitle,
                                    scope: this,
                                    handler: function(btn) {
                                        var form = btn.up('form').getForm();
                                        if (!form.isValid()) {
                                            var fields = form.getFields();
                                            var errors = [];

                                            /* Create Error-String and display Error-Window */
                                            for (i = 0; i < fields.length; i++) {
                                                errors.push(fields.items[i].getErrors().join(', '));
                                            }

                                            var errorsWindow = new Ext.Window({
                                                title: panel._errorsTitle,
                                                html: errors,
                                                width: 350
                                            });

                                            return errorsWindow.show();
                                        }

                                        var values = form.getValues();
                                        Ext.Ajax.request({
                                            url: url + 'customer/save',
                                            params: values,
                                            scope: this,
                                            success: function(response) {
                                                window.close();
                                            },
                                            failure: function(response) {
                                                /* If response text is less than 200 chars long (means not an exception
                                                 * stack trace), use response text. If not, show common help/error text
                                                 */
                                                message = response.responseText.length < 200
                                                    ? response.responseText
                                                    : panel._seriousErrorTitle;
                                                showNotification(panel._errorTitle, message, false);
                                            }
                                        });
                                    }
                                }
                            ]
                        })
                    ]
                });

                window.show();
            },
            deleteCustomer: function(record) {
                var grid = this;
                var id = parseInt(record.id);
                Ext.Msg.confirm('Achtung', 'Wirklich löschen?<br />' + record.name, function(btn) {
                    if (btn == 'yes') {
                        Ext.Ajax.request({
                            url: url + 'customer/delete',
                            params: {
                                id: id
                            },
                            scope: this,
                            success: function(response) {
                                grid.refresh();
                            },
                            failure: function(response) {
                                var data = Ext.decode(response.responseText);
                                showNotification(grid._errorTitle, data.message, false);
                            }
                        });
                    }
                });
            },
            refresh: function() {
                this.store.load();
                this.teamStore.load();
                this.getView().refresh();
            }
        });

        var projectGrid = Ext.create('Ext.grid.Panel', {
            customerStore: this.customerStore,
            ticketSystemStore: this.ticketSystemStore,
            store: this.projectStore,
            columns: [
                {
                    header: 'Id',
                    dataIndex: 'id',
                    hidden: true
                }, {
                    header: this._nameTitle,
                    dataIndex: 'name',
                    flex: 1,
                    field: {
                        xtype: 'textfield'
                    }
                },
                {
                    header: this._customerTitle,
                    dataIndex: 'customer',
                    flex: 1,
                    field: {
                        xtype: 'textfield',
                        lazyRender: true,
                        queryMode: 'local',
                        displayField: 'name',
                        valueField: 'id',
                        anchor: '100%'
                    },
                    renderer: function(id) {
                        var record = this.customerStore.getById(id);
                        return record ? record.get('name') : id;
                    }
                },
                {
                    header: this._ticketPrefixTitle,
                    dataIndex: 'jiraId',
                    flex: 1,
                    field: {
                        xtype: 'textfield'
                    }
                },
                {
                    header: this._ticketNumberTitle,
                    dataIndex: 'jiraTicket',
                    flex: 1,
                    field: {
                        xtype: 'textfield'
                    }
                },
                {
                    header: this._ticketSystemTitle,
                    dataIndex: 'ticket_system',
                    flex: 1,
                    field: {
                        xtype: 'textfield',
                        lazyRender: true,
                        queryMode: 'local',
                        store: this.ticketSystemStore,
                        displayField: 'name',
                        valueField: 'id',
                        anchor: '100%'
                    },
                    renderer: function(id) {
                        if (1 > parseInt(id))
                            return '';

                        var record = this.ticketSystemStore.getById(id);
                        return record ? record.get('name') : id;
                    }
                },
                {
                    header: this._additionalInformationFromExternal,
                    dataIndex: 'additionalInformationFromExternal',
                    field: {
                        xtype: 'checkbox'
                    },
                    renderer: function(value) {
                        return renderCheckbox(value);
                    }
                },
                {
                    header: this._activeTitle,
                    dataIndex: 'active',
                    field: {
                        xtype: 'checkbox'
                    },
                    renderer: function(value) {
                        return renderCheckbox(value);
                    }
                },
                {
                    header: this._globalTitle,
                    dataIndex: 'global',
                    field: {
                        xtype: 'checkbox'
                    },
                    renderer: function(value) {
                        return renderCheckbox(value);
                    }
                },
                {
                    header: this._offerTitle,
                    dataIndex: 'offer',
                    width: 70,
                    flex: 1,
                    field: {
                        xtype: 'textfield'
                    }
                },
                {
                    header: this._costCenterTitle,
                    dataIndex: 'cost_center',
                    width: 70,
                    flex: 1,
                    field: {
                        xtype: 'textfield'
                    }
                },
                {
                    header: this._billingTitle,
                    dataIndex: 'billing',
                    width: 70,
                    flex: 1,
                    field: {
                        xtype: 'textfield',
                        queryMode: 'local',
                        store: billingStore,
                        displayField: 'displayname',
                        valueField: 'value',
                        anchor: '100%'
                    },
                    renderer: function(value) {
                        var record = billingStore.findRecord('value', value);
                        return record ? record.get('displayname') : value;
                    }
                },
                {
                    header: this._estimationTitle,
                    dataIndex: 'estimation',
                    width: 100,
                    flex: 1,
                    align: 'right',
                    field: {
                        xtype: 'textfield'
                    },
                    renderer: function(value) {
                        return formatDuration(value, true);
                    }
                }
            ],
            tbar: [
                {
                    text: this._addProjectTitle,
                    iconCls: 'icon-add',
                    scope: this,
                    handler: function() {
                        projectGrid.editProject();
                    }
                }, {
                    text: this._refreshTitle,
                    iconCls: 'icon-refresh',
                    scope: this,
                    handler: function() {
                        projectGrid.refresh();
                    }
                }, {
                    text: this._projectSubticketsSyncTitle,
                    iconCls: 'icon-refresh',
                    scope: this,
                    handler: function() {
                        projectGrid.syncAllProjectSubtickets();
                    }
                }
            ],
            listeners: {
                /* Right-click menu */
                itemcontextmenu: function(grid, record, item, index, event, options) {
                    event.stopEvent();

                    var contextMenu = Ext.create('Ext.menu.Menu', {
                        items: [
                            {
                                text: panel._editTitle,
                                iconCls: 'icon-edit',
                                scope: this,
                                handler: function() {
                                    this.editProject(record.data);
                                }
                            },
                            {
                                text: panel._deleteTitle,
                                iconCls: 'icon-delete',
                                scope: this,
                                handler: function() {
                                    this.deleteProject(record.data);
                                }
                            },
                            {
                                xtype: 'menuseparator'
                            },
                            {
                                text: panel._projectSubticketsTitle,
                                iconCls: 'icon-info',
                                scope: this,
                                handler: function() {
                                    this.showProjectSubtickets(record.data);
                                }
                            },
                            {
                                text: panel._projectSubticketsSyncTitle,
                                iconCls: 'icon-refresh',
                                scope: this,
                                handler: function() {
                                    this.syncProjectSubtickets(record.data);
                                }
                            }
                        ]
                    });

                    contextMenu.showAt(event.xy);
                }
            }, // end listeners
            editProject: function(record) {
                var projectLeadStore = Ext.create('Netresearch.store.AdminUsers');
                var technicalLeadStore = Ext.create('Netresearch.store.AdminUsers');
                var projectStore = Ext.create('Netresearch.store.AdminProjects', {
                    autoLoad: false
                });

                projectLeadStore.load();
                technicalLeadStore.load();

                if (!record) {
                    record = {};
                }

                var window = Ext.create('Ext.window.Window', {
                    title: panel._editProjectTitle,
                    modal: true,
                    width: 400,
                    id: 'edit-project-window',
                    layout: 'fit',
                    listeners: {
                        destroy: {
                            scope: this,
                            fn: function() {
                                this.refresh();
                            }
                        }
                    },
                    items: [
                        new Ext.form.Panel({
                            bodyPadding: 5,
                            defaultType: 'textfield',
                            items: [
                                new Ext.form.field.Hidden({
                                    name: 'id',
                                    value: record.id ? record.id : 0
                                }), {
                                    fieldLabel: panel._nameTitle,
                                    name: 'name',
                                    anchor: '100%',
                                    value: record.name ? record.name : ''
                                },
                                new Ext.form.ComboBox({
                                    fieldLabel: panel._customerTitle,
                                    name: 'customer',
                                    store: this.customerStore,
                                    queryMode: 'local',
                                    displayField: 'name',
                                    valueField: 'id',
                                    anchor: '100%',
                                    disabled: record.customer ? true : false,
                                    listeners: {
                                        afterrender: function(field, value) {
                                            projectStore.load({
                                                params: {
                                                    customer: field.getValue()
                                                }
                                            });
                                        },
                                        select: function(field, value) {
                                            projectStore.load({
                                                params: {
                                                    customer: field.getValue()
                                                }
                                            });
                                        }
                                    },
                                    value: record.customer ? record.customer : ''
                                }),
                                new Ext.form.ComboBox({
                                    fieldLabel: panel._ticketSystemTitle,
                                    name: 'ticket_system',
                                    allowBlank: true,
                                    store: this.ticketSystemStore,
                                    queryMode: 'local',
                                    displayField: 'name',
                                    valueField: 'id',
                                    anchor: '100%',
                                    value: record.ticket_system ? record.ticket_system : ''
                                }),
                                {
                                    fieldLabel: panel._ticketPrefixTitle,
                                    afterSubTpl: panel._ticketPrefixTitleHelp,
                                    name: 'jiraId',
                                    anchor: '100%',
                                    value: record.jiraId ? record.jiraId : ''
                                },
                                {
                                    fieldLabel: panel._ticketNumberTitle,
                                    afterSubTpl: panel._ticketNumberTitleHelp,
                                    name: 'jiraTicket',
                                    anchor: '100%',
                                    value: record.jiraTicket ? record.jiraTicket : ''
                                },
                                new Ext.form.field.Checkbox({
                                    fieldLabel: panel._additionalInformationFromExternal,
                                    name: 'additionalInformationFromExternal',
                                    inputValue: 1,
                                    checked: record.additionalInformationFromExternal ? record.additionalInformationFromExternal : 0
                                }),
                                new Ext.form.field.Checkbox({
                                    fieldLabel: panel._activeTitle,
                                    name: 'active',
                                    inputValue: 1,
                                    checked: record.active ? record.active : 0
                                }),
                                new Ext.form.field.Checkbox({
                                    fieldLabel: panel._globalTitle + '(' + panel._forAllCustomersTitle + ')',
                                    name: 'global',
                                    inputValue: 1,
                                    checked: record.global ? record.global : 0
                                }),
                                new Ext.form.ComboBox({
                                    fieldLabel: panel._projectLeadTitle,
                                    name: 'project_lead',
                                    allowBlank: true,
                                    store: projectLeadStore,
                                    queryMode: 'local',
                                    displayField: 'username',
                                    valueField: 'id',
                                    anchor: '100%',
                                    value: record.project_lead ? record.project_lead : ''
                                }),
                                new Ext.form.ComboBox({
                                    fieldLabel: panel._technicalLeadTitle,
                                    name: 'technical_lead',
                                    allowBlank: true,
                                    store: technicalLeadStore,
                                    queryMode: 'local',
                                    displayField: 'username',
                                    valueField: 'id',
                                    anchor: '100%',
                                    value: record.technical_lead ? record.technical_lead : ''
                                }),
                                {
                                    fieldLabel: panel._offerTitle,
                                    name: 'offer',
                                    anchor: '100%',
                                    enforceMaxLength: true,
                                    maxLength: 31,
                                    maxLengthText: panel._max31CharactersTitle,
                                    value: record.offer ? record.offer : ''
                                },
                                {
                                    fieldLabel: panel._costCenterTitle,
                                    name: 'cost_center',
                                    anchor: '100%',
                                    enforceMaxLength: true,
                                    maxLength: 31,
                                    maxLengthText: panel._max31CharactersTitle,
                                    value: record.cost_center ? record.cost_center : ''
                                }, new Ext.form.ComboBox({
                                    fieldLabel: panel._billingTitle,
                                    name: 'billing',
                                    store: billingStore,
                                    queryMode: 'local',
                                    lazyRenderer: true,
                                    displayField: 'displayname',
                                    valueField: 'value',
                                    multiSelect: false,
                                    typeAhead: true,
                                    triggerAction: 'all',
                                    anchor: '100%',
                                    value: record.billing ? record.billing : 0
                                }),
                                {
                                    fieldLabel: panel._estimationTitle,
                                    name: 'estimation',
                                    anchor: '100%',
                                    value: record.estimationText ? record.estimationText : ''
                                },
                                {
                                    fieldLabel: panel._internalJiraProjectKey,
                                    name: 'internalJiraProjectKey',
                                    anchor: '100%',
                                    value: record.internalJiraProjectKey ? record.internalJiraProjectKey : ''
                                },
                                new Ext.form.ComboBox({
                                    fieldLabel: panel._internalJiraTicketSystem,
                                    name: 'internalJiraTicketSystem',
                                    allowBlank: true,
                                    store: this.ticketSystemStore,
                                    queryMode: 'local',
                                    displayField: 'name',
                                    valueField: 'id',
                                    anchor: '100%',
                                    value: record.internalJiraTicketSystem ? record.internalJiraTicketSystem : ''
                                })
                            ],
                            buttons: [
                                {
                                    text: panel._saveTitle,
                                    scope: this,
                                    handler: function(btn) {
                                        var form = btn.up('form').getForm();
                                        var values = form.getValues();

                                        Ext.Ajax.request({
                                            url: url + 'project/save',
                                            params: values,
                                            scope: this,
                                            success: function(response) {
                                                let data = Ext.decode(response.responseText);
                                                if (data.message) {
                                                    showNotification(panel._errorTitle, data.message, false);
                                                }
                                                window.close();
                                            },
                                            failure: function(response) {
                                                /*
                                                 * If responsetext is less than 200 chars long (means not an exception
                                                 * stack trace), use responsetext. If not, show common help/error text
                                                 */
                                                var message = response.responseText.length < 200
                                                    ? response.responseText
                                                    : panel._seriousErrorTitle;
                                                showNotification(panel._errorTitle, message, false);
                                            }
                                        });
                                    }
                                }
                            ]
                        })
                    ]
                });

                window.show();
            },
            deleteProject: function(record) {
                var grid = this;
                var id = parseInt(record.id);
                Ext.Msg.confirm('Achtung', 'Wirklich löschen?<br />' + record.name, function(btn) {
                    if (btn == 'yes') {
                        Ext.Ajax.request({
                            url: url + 'project/delete',
                            params: {
                                id: id
                            },
                            scope: this,
                            success: function(response) {
                                grid.refresh();
                            },
                            failure: function(response) {
                                var data = Ext.decode(response.responseText);
                                showNotification(panel._errorTitle, data.message, false);
                            }
                        });
                    }
                });
            },
            showProjectSubtickets: function(project) {
                Ext.Msg.alert(
                    panel._projectSubticketsTitle + ': ' + project['name'],
                    project['jiraTicket'] + "<br/>\n"
                    + project['subtickets']
                );
            },
            syncProjectSubtickets: function(project) {
                var grid = this;
                Ext.Ajax.request({
                    method: 'POST',
                    url: url + 'projects/' + project.id + '/syncsubtickets',
                    scope: this,
                    success: function(response) {
                        grid.refresh();
                        grid.showProjectSubtickets(project);
                    },
                    failure: function(response) {
                        var data = Ext.decode(response.responseText);
                        showNotification(panel._errorTitle, data.message, false);
                    }
                });
            },
            syncAllProjectSubtickets: function() {
                var grid = this;
                Ext.Ajax.request({
                    method: 'POST',
                    url: url + 'projects/syncsubtickets',
                    scope: this,
                    success: function(response) {
                        grid.refresh();
                        showNotification(panel._successTitle, panel._subticketSyncFinishedTitle, true);
                    },
                    failure: function(response) {
                        var data = Ext.decode(response.responseText);
                        showNotification(panel._errorTitle, data.message, false);
                    }
                });
            },
            refresh: function() {
                this.customerStore.load();
                this.ticketSystemStore.load();
                this.store.load();
                this.getView().refresh();
            }
        });

        var userGrid = Ext.create('Ext.grid.Panel', {
            store: this.userStore,
            teamStore: this.teamStore,
            columns: [
                {
                    header: 'Id',
                    dataIndex: 'id',
                    hidden: true
                }, {
                    header: this._userNameTitle,
                    dataIndex: 'username',
                    flex: 1,
                    field: {
                        xtype: 'textfield'
                    }
                },
                {
                    header: this._abbreviationTitle,
                    dataIndex: 'abbr',
                    flex: 1,
                    field: {
                        xtype: 'textfield'
                    }
                },
                {
                    header: this._typeTitle,
                    dataIndex: 'type',
                    flex: 1,
                    field: {
                        xtype: 'textfield'
                    }
                },
                {
                    header: this._teamsTitle,
                    dataIndex: 'teams',
                    flex: 1,
                    renderer: function(value) {
                        /* Display space seperated list of related teams */
                        var output = '';
                        Ext.each(value, function(teamId) {
                            if (isNaN(teamId)) {
                              return;
                            }
                            var team = userGrid.teamStore.getById(parseInt(teamId));
                            if (null == team) {
                              return;
                            }
                            if (output != '')
                                output += ', ';
                            output += team.data.name;
                        });
                        return output;
                    }
                }
            ],
            tbar: [
                {
                    text: this._addUserTitle,
                    iconCls: 'icon-add',
                    scope: this,
                    handler: function() {
                        userGrid.editUser();
                    }
                }, {
                    text: this._refreshTitle,
                    iconCls: 'icon-refresh',
                    scope: this,
                    handler: function() {
                        userGrid.refresh();
                    }
                }
            ],
            listeners: {
                /* Right-click menu */
                itemcontextmenu: function(grid, record, item, index, event, options) {
                    event.stopEvent();

                    var contextMenu = Ext.create('Ext.menu.Menu', {
                        items: [
                            {
                                text: panel._editTitle,
                                iconCls: 'icon-edit',
                                scope: this,
                                handler: function() {
                                    this.editUser(record.data);
                                }
                            }, {
                                text: panel._deleteTitle,
                                iconCls: 'icon-delete',
                                scope: this,
                                handler: function() {
                                    this.deleteUser(record.data);
                                }
                            }
                        ]
                    });

                    contextMenu.showAt(event.xy);
                }
            },
            editUser: function(record) {
                if(!record) record = {};

                var teamStore = this.teamStore;
                teamStore.load();

                var localesStore = new Ext.data.ArrayStore({
                    fields: ['value', 'displayname'],
                    data: [
                        ['de', 'Deutsch'],
                        ['en', 'English'],
                        ['es', 'Español'],
                        ['fr', 'Français'],
                        ['ru', 'Русский'],
                    ]
                });

                var window = Ext.create('Ext.window.Window', {
                    title: panel._editUserTitle,
                    modal: true,
                    width: 400,
                    id: 'edit-user-window',
                    layout: 'fit',
                    listeners: {
                        destroy: {
                            scope: this,
                            fn: function() {
                                this.refresh();
                            }
                        }
                    },
                    items: [
                        new Ext.form.Panel({
                            bodyPadding: 5,
                            defaultType: 'textfield',
                            items: [
                                new Ext.form.field.Hidden({
                                    name: 'id',
                                    value: record.id ? record.id : 0
                                }), {
                                    fieldLabel: panel._userNameTitle,
                                    name: 'username',
                                    anchor: '100%',
                                    value: record.username ? record.username : ''
                                }, {
                                    fieldLabel: panel._abbreviationTitle,
                                    name: 'abbr',
                                    anchor: '100%',
                                    value: record.abbr ? record.abbr : ''
                                }, new Ext.form.ComboBox({
                                    fieldLabel: panel._languageTitle,
                                    name: 'locale',
                                    store: localesStore,
                                    queryMode: 'local',
                                    displayField: 'displayname',
                                    valueField: 'value',
                                    multiSelect: false,
                                    typeAhead: true,
                                    triggerAction: 'all',
                                    anchor: '100%',
                                    value: record.locale ? record.locale : 'de'
                                }), new Ext.form.ComboBox({
                                    fieldLabel: 'Typ',
                                    name: 'type',
                                    anchor: '100%',
                                    store: Ext.create('Ext.data.Store', {
                                        fields: ['type', 'name'],
                                        data: [
                                            { 'type':'DEV', 'name': panel._developerTitle},
                                            { 'type':'PL', 'name': panel._projectManagerTitle},
                                            { 'type':'CTL', 'name': panel._controllingTitle}
                                        ]
                                    }),
                                    queryMode: 'local',
                                    displayField: 'name',
                                    valueField: 'type',
                                    value: record.type ? record.type : ''
                                }),
                                new Ext.form.ComboBox({
                                    fieldLabel: panel._teamsTitle,
                                    name: 'teams[]',
                                    store: teamStore,
                                    queryMode: 'local',
                                    displayField: 'name',
                                    valueField: 'id',
                                    multiSelect: true,
                                    typeAhead: true,
                                    triggerAction: 'all',
                                    anchor: '100%',
                                    listeners: {
                                        afterrender: function(field, value) {
                                            teamStore.load({
                                                params: {
                                                    team: field.getValue()
                                                }
                                            });
                                        },
                                        select: function(field, value) {
                                            teamStore.load({
                                                params: {
                                                    team: field.getValue()
                                                }
                                            });
                                        }
                                    },
                                    value: record.teams ? record.teams : ''
                                })
                            ],
                            buttons: [
                                {
                                    text: panel._saveTitle,
                                    scope: this,
                                    handler: function(btn) {
                                        var form = btn.up('form').getForm();
                                        var values = form.getValues();

                                        Ext.Ajax.request({
                                            url: url + 'user/save',
                                            params: values,
                                            scope: this,
                                            success: function(response) {
                                                window.close();
                                            },
                                            failure: function(response) {
                                                /*
                                                 * If responsetext is less than 200 chars long (means not an exception
                                                 * stack trace), use responsetext. If not, show common help/error text
                                                 */
                                                var message = response.responseText.length < 200
                                                    ? response.responseText
                                                    : panel._seriousErrorTitle;
                                                showNotification(panel._errorTitle, message, false);
                                            }
                                        });
                                    }
                                }
                            ]
                        })
                    ]
                });

                window.show();
            },
            deleteUser: function(record) {
                var grid = this;
                var id = parseInt(record.id);
                Ext.Msg.confirm('Achtung', 'Wirklich löschen?<br />' + record.username, function(btn) {
                    if (btn == 'yes') {
                        Ext.Ajax.request({
                            url: url + 'user/delete',
                            params: {
                                id: id
                            },
                            scope: this,
                            success: function(response) {
                                grid.refresh();
                            },
                            failure: function(response) {
                                var data = Ext.decode(response.responseText);
                                showNotification(grid._errorTitle, data.message, false);
                            }
                        });
                    }
                });
            },
            refresh: function() {
                this.teamStore.load();
                this.store.load();
                this.getView().refresh();
            }
        });

        var teamGrid = Ext.create('Ext.grid.Panel', {
            userStore: this.userStore,
            store: this.teamStore,
            columns: [
                {
                    header: this._teamTitle,
                    dataIndex: 'name',
                    flex: 1,
                    field: {
                        xtype: 'textfield'
                    }
                }, {
                    header: this._teamLeadTitle,
                    dataIndex: 'lead_user_id',
                    flex: 1,
                    field: {
                        xtype: 'textfield',
                        lazyRender: true,
                        queryMode: 'local',
                        displayField: 'name',
                        valueField: 'lead_user_id',
                        anchor: '100%'
                    },
                    renderer: function(id) {
                        var record = this.userStore.getById(id);
                        return record ? record.get('username') : id;
                    }
                }
            ],
            tbar: [
                {
                    text: this._addTeamTitle,
                    iconCls: 'icon-add',
                    scope: this,
                    handler: function() {
                        teamGrid.editTeam();
                    }
                }, {
                    text: this._refreshTitle,
                    iconCls: 'icon-refresh',
                    scope: this,
                    handler: function() {
                        teamGrid.refresh();
                    }
                }
            ],
            listeners: {
                /* Right-click menu */
                itemcontextmenu: function(grid, record, item, index, event, options) {
                    event.stopEvent();

                    var contextMenu = Ext.create('Ext.menu.Menu', {
                        items: [
                            {
                                text: panel._editTitle,
                                iconCls: 'icon-edit',
                                scope: this,
                                handler: function() {
                                    this.editTeam(record.data);
                                }
                            }, {
                                text: panel._deleteTitle,
                                iconCls: 'icon-delete',
                                scope: this,
                                handler: function() {
                                    this.deleteTeam(record.data);
                                }
                            }
                        ]
                    });

                    contextMenu.showAt(event.xy);
                }
            },
            editTeam: function(record) {
                var leadUserStore = Ext.create('Netresearch.store.AdminUsers');
                leadUserStore.load();
                record = record || {};

                var window = Ext.create('Ext.window.Window', {
                    title: panel._editTeamTitle,
                    modal: true,
                    width: 400,
                    id: 'edit-team-window',
                    layout: 'fit',
                    listeners: {
                        destroy: {
                            scope: this,
                            fn: function() {
                                this.refresh();
                            }
                        }
                    },
                    items: [
                        new Ext.form.Panel({
                            bodyPadding: 5,
                            defaultType: 'textfield',
                            items: [
                                new Ext.form.field.Hidden({
                                    name: 'id',
                                    value: record.id ? record.id : 0
                                }), {
                                    fieldLabel: panel._nameTitle,
                                    name: 'name',
                                    anchor: '100%',
                                    value: record.name ? record.name : ''
                                }, new Ext.form.ComboBox({
                                    fieldLabel: panel._teamLeadTitle,
                                    name: 'lead_user_id',
                                    store: leadUserStore,
                                    queryMode: 'local',
                                    displayField: 'username',
                                    valueField: 'id',
                                    multiSelect: false,
                                    typeAhead: true,
                                    triggerAction: 'all',
                                    anchor: '100%',
                                    value: record.lead_user_id ? record.lead_user_id : ''
                                })
                            ],
                            buttons: [
                                {
                                    text: panel._saveTitle,
                                    scope: this,
                                    handler: function(btn) {
                                        var form = btn.up('form').getForm();
                                        var values = form.getValues();

                                        Ext.Ajax.request({
                                            url: url + 'team/save',
                                            params: values,
                                            scope: this,
                                            success: function(response) {
                                                window.close();
                                                showNotification(panel._successTitle, panel._teamSavedTitle, true);
                                            },
                                            failure: function(response) {
                                                /*
                                                 * If responsetext is less than 200 chars long (means not an exception
                                                 * stack trace), use responsetext. If not, show common help/error text
                                                 */
                                                var message = response.responseText.length < 200
                                                    ? response.responseText
                                                    : panel._seriousErrorTitle;
                                                showNotification(panel._errorTitle, message, false);
                                            }
                                        });
                                    }
                                }
                            ]
                        })
                    ]
                });

                window.show();
            },
            deleteTeam: function(record) {
                var grid = this;
                var id = parseInt(record.id);
                Ext.Msg.confirm('Achtung', 'Wirklich löschen?<br />' + record.name, function(btn) {
                    if (btn == 'yes') {
                        Ext.Ajax.request({
                            url: url + 'team/delete',
                            params: {
                                id: id
                            },
                            scope: this,
                            success: function(response) {
                                grid.refresh();
                            },
                            failure: function(response) {
                                var data = Ext.decode(response.responseText);
                                showNotification(grid._errorTitle, data.message, false);
                            }
                        });
                    }
                });
            },
            refresh: function() {
                this.userStore.load();
                this.store.load();
                this.getView().refresh();
            }
        });


        var presetGrid = Ext.create('Ext.grid.Panel', {
            customerStore: this.customerStore,
            projectStore: this.projectStore,
            activityStore: this.activityStore,
            store: this.presetStore,
            columns: [
                {
                    header: this._nameTitle,
                    dataIndex: 'name',
                    flex: 1,
                    field: {
                        xtype: 'textfield'
                    }
                },
                {
                    header: this._customerTitle,
                    dataIndex: 'customer',
                    flex: 1,
                    field: {
                        xtype: 'textfield',
                        lazyRender: true,
                        queryMode: 'local',
                        displayField: 'name',
                        valueField: 'id',
                        anchor: '100%'
                    },
                    renderer: function(id) {
                        var record = this.customerStore.getById(id);
                        return record ? record.get('name') : id;
                    }
                },
                {
                    header: this._projectTitle,
                    dataIndex: 'project',
                    flex: 1,
                    field: {
                        xtype: 'textfield',
                        lazyRender: true,
                        queryMode: 'local',
                        displayField: 'name',
                        valueField: 'id',
                        anchor: '100%'
                    },
                    renderer: function(id) {
                        var record = this.projectStore.getById(id);
                        return record ? record.get('name') : id;
                    }
                },
                {
                    header: this._activityTitle,
                    dataIndex: 'activity',
                    flex: 1,
                    field: {
                        xtype: 'textfield',
                        lazyRender: true,
                        queryMode: 'local',
                        displayField: 'name',
                        valueField: 'id',
                        anchor: '100%'
                    },
                    renderer: function(id) {
                        var record = this.activityStore.getById(id);
                        return record ? record.get('name') : id;
                    }
                },
                {
                    header: this._descriptionTitle,
                    dataIndex: 'description',
                    flex: 1,
                    field: {
                        xtype: 'textfield'
                    }
                }
            ],
            tbar: [
                {
                    text: this._addPresetTitle,
                    iconCls: 'icon-add',
                    scope: this,
                    handler: function() {
                        presetGrid.editPreset();
                    }
                }, {
                    text: this._refreshTitle,
                    iconCls: 'icon-refresh',
                    scope: this,
                    handler: function() {
                        presetGrid.refresh();
                    }
                }
            ],
            listeners: {
                /* Right-click menu */
                itemcontextmenu: function(grid, record, item, index, event, options) {
                    event.stopEvent();

                    var contextMenu = Ext.create('Ext.menu.Menu', {
                        items: [
                            {
                                text: panel._editTitle,
                                iconCls: 'icon-edit',
                                scope: this,
                                handler: function() {
                                    this.editPreset(record.data);
                                }
                            }, {
                                text: panel._deleteTitle,
                                iconCls: 'icon-delete',
                                scope: this,
                                handler: function() {
                                    this.deletePreset(record);
                                }
                            }
                        ]
                    });

                    contextMenu.showAt(event.xy);
                }
            }, // end listeners
            deletePreset: function(record) {
                var grid = this;
                var id = parseInt(record.data.id);
                Ext.Msg.confirm('Achtung', 'Wirklich löschen?<br />' + record.data.name, function(btn) {
                    if (btn == 'yes') {
                        Ext.Ajax.request({
                            url: url + 'preset/delete',
                            params: {
                                    id: id
                            },
                            scope: this,
                            success: function(response) {
                                grid.refresh();
                            },
                            failure: function(response) {
                                var data = Ext.decode(response.responseText);
                                showNotification(grid._errorTitle, data.message, false);
                            }
                        });
                    }
                });
            },
            editPreset: function(record) {
                var projectStore = Ext.create('Netresearch.store.AdminProjects');
                projectStore.load();
                var presetStore = Ext.create('Netresearch.store.AdminPresets', {
                    autoLoad: false
                });

                if(!record) record = {};

                var window = Ext.create('Ext.window.Window', {
                    title: panel._editPresetTitle,
                    modal: true,
                    width: 400,
                    id: 'edit-preset-window',
                    layout: 'fit',
                    listeners: {
                        destroy: {
                            scope: this,
                            fn: function() {
                                this.refresh();
                            }
                        }
                    },
                    items: [
                        new Ext.form.Panel({
                            bodyPadding: 5,
                            defaultType: 'textfield',
                            items: [
                                new Ext.form.field.Hidden({
                                    name: 'id',
                                    value: record.id ? record.id : 0
                                }), {
                                    fieldLabel: panel._nameTitle,
                                    name: 'name',
                                    anchor: '100%',
                                    value: record.name ? record.name : ''
                                },
                                new Ext.form.ComboBox({
                                    fieldLabel: panel._customerTitle,
                                    name: 'customer',
                                    id: 'preset-edit-customer',
                                    store: this.customerStore,
                                    queryMode: 'local',
                                    displayField: 'name',
                                    valueField: 'id',
                                    anchor: '100%',
                                    value: record.customer ? record.customer : ''
                                }),
                                new Ext.form.ComboBox({
                                    fieldLabel: panel._projectTitle,
                                    name: 'project',
                                    store: projectStore,
                                    queryMode: 'local',
                                    displayField: 'name',
                                    valueField: 'id',
                                    anchor: '100%',
                                    value: record.project ? record.project : '',
                                    listeners: {
                                        scope: this,
                                        focus: function() {
                                            projectStore.load({
                                                params: {
                                                    customer: Ext.getCmp('preset-edit-customer').getValue()
                                                }
                                            });
                                        }
                                    }

                                }),
                                new Ext.form.ComboBox({
                                    fieldLabel: panel._activityTitle,
                                    name: 'activity',
                                    store: this.activityStore,
                                    queryMode: 'local',
                                    displayField: 'name',
                                    valueField: 'id',
                                    anchor: '100%',
                                    value: record.activity ? record.activity : ''
                                }), {
                                    fieldLabel: panel._descriptionTitle,
                                    name: 'description',
                                    anchor: '100%',
                                    value: record.description ? record.description : ''
                                }
                            ],
                            buttons: [
                                {
                                    text: 'Speichern',
                                    scope: this,
                                    handler: function(btn) {
                                        var form = btn.up('form').getForm();
                                        var values = form.getValues();

                                        Ext.Ajax.request({
                                            url: url + 'preset/save',
                                            params: values,
                                            scope: this,
                                            success: function(response) {
                                                window.close();
                                            },
                                            failure: function(response) {
                                                /*
                                                 * If responsetext is less than 200 chars long (means not an exception
                                                 * stack trace), use responsetext. If not, show common help/error text
                                                 */
                                                var message = response.responseText.length < 200
                                                    ? response.responseText
                                                    : panel._seriousErrorTitle;
                                                showNotification(panel._errorTitle, message, false);
                                            }
                                        });
                                    }
                                }
                            ]
                        })
                    ]
                });

                window.show();
            },
            refresh: function(){
                this.customerStore.load();
                this.projectStore.load();
                this.activityStore.load();
                this.store.load();
                this.getView().refresh();
            }
        });


        var ticketSystemGrid = Ext.create('Ext.grid.Panel', {
            store: this.ticketSystemStore,
            columns: [
                {
                    header: this._nameTitle,
                    dataIndex: 'name',
                    flex: 1,
                    field: {
                        xtype: 'textfield'
                    }
                }, {
                    header: this._typeTitle,
                    dataIndex: 'type',
                    flex: 1,
                    field: {
                        xtype: 'textfield'
                    }
                }, {
                    header: this._timebookingTitle,
                    dataIndex: 'bookTime',
                    field: {
                        xtype: 'checkbox'
                    },
                    renderer: function(value) {
                        return renderCheckbox(value);
                    }
                }, {
                    header: this._urlTitle,
                    dataIndex: 'url',
                    flex: 1,
                    field: {
                        xtype: 'textfield'
                    }
                }, {
                    header: this._ticketUrlTitle,
                    dataIndex: 'ticketUrl',
                    flex: 1,
                    field: {
                        xtype: 'textfield'
                    }
                }, {
                    header: this._oauthConsumerKeyTitle,
                    dataIndex: 'oauthConsumerKey',
                    flex: 1,
                    field: {
                        xtype: 'textfield'
                    }
                }
            ],
            tbar: [
                {
                    text: this._addTicketSystemTitle,
                    iconCls: 'icon-add',
                    scope: this,
                    handler: function() {
                        ticketSystemGrid.editTicketSystem();
                    }
                }, {
                    text: this._refreshTitle,
                    iconCls: 'icon-refresh',
                    scope: this,
                    handler: function() {
                        ticketSystemGrid.refresh();
                    }
                }
            ],
            listeners: {
                /* Right-click menu */
                itemcontextmenu: function(grid, record, item, index, event, options) {
                    event.stopEvent();

                    var contextMenu = Ext.create('Ext.menu.Menu', {
                        items: [
                            {
                                text: panel._editTitle,
                                iconCls: 'icon-edit',
                                scope: this,
                                handler: function() {
                                    this.editTicketSystem(record.data);
                                }
                            }, {
                                text: panel._deleteTitle,
                                iconCls: 'icon-delete',
                                scope: this,
                                handler: function() {
                                    this.deleteTicketSystem(record.data);
                                }
                            }
                        ]
                    });

                    contextMenu.showAt(event.xy);
                }
            }, // end listeners
            editTicketSystem: function(record) {
                var ticketSytemStore = Ext.create('Netresearch.store.TicketSystems', {
                    autoLoad: false
                });

                var ticketSystemTypeStore = new Ext.data.ArrayStore({
                    fields: ['type'],
                    data: [
                            ['JIRA'], ['OTRS'], ['FRESHDESK']
                        ]
                });

                if(!record) record = {};

                var window = Ext.create('Ext.window.Window', {
                    title: panel._editTicketSystemTitle,
                    modal: true,
                    width: 600,
                    id: 'edit-ticket-system-window',
                    layout: 'fit',
                    listeners: {
                        destroy: {
                            scope: this,
                            fn: function() {
                                this.refresh();
                            }
                        }
                    },
                    items: [
                        new Ext.form.Panel({
                            bodyPadding: 5,
                            defaultType: 'textfield',
                            items: [
                                new Ext.form.field.Hidden({
                                    name: 'id',
                                    value: record.id ? record.id : 0
                                }), {
                                    fieldLabel: panel._nameTitle,
                                    name: 'name',
                                    anchor: '100%',
                                    value: record.name ? record.name : ''
                                },
                                new Ext.form.ComboBox({
                                    fieldLabel: panel._typeTitle,
                                    name: 'type',
                                    id: 'ticketsystem-edit-type',
                                    store: ticketSystemTypeStore,
                                    queryMode: 'local',
                                    displayField: 'type',
                                    valueField: 'type',
                                    anchor: '100%',
                                    value: record.type ? record.type : ''
                                }),
                                new Ext.form.field.Checkbox({
                                    fieldLabel: panel._timebookingTitle,
                                    name: 'bookTime',
                                    inputValue: 1,
                                    checked: record.bookTime ? record.bookTime : 0
                                }), {
                                    fieldLabel: panel._urlTitle,
                                    name: 'url',
                                    anchor: '100%',
                                    value: record.url ? record.url : ''
                                }, {
                                    fieldLabel: panel._ticketUrlTitle + '<br />' + panel._ticketUrlHint,
                                    name: 'ticketUrl',
                                    anchor: '100%',
                                    value: record.ticketUrl ? record.ticketUrl : ''
                                }, {
                                    fieldLabel: panel._loginTitle,
                                    name: 'login',
                                    anchor: '100%',
                                    value: record.login ? record.login : ''
                                }, {
                                    fieldLabel: panel._passwordTitle,
                                    name: 'password',
                                    anchor: '100%',
                                    value: record.password ? record.password : ''
                                },
                                new Ext.form.field.TextArea({
                                    fieldLabel: panel._publicKeyTitle,
                                    name: 'publicKey',
                                    anchor: '100%',
                                    grow: true,
                                    value: record.publicKey ? record.publicKey : ''
                                }),
                                new Ext.form.field.TextArea({
                                    fieldLabel: panel._privateKeyTitle,
                                    name: 'privateKey',
                                    anchor: '100%',
                                    grow: true,
                                    growMin: 130,
                                    value: record.privateKey ? record.privateKey : ''
                                }), {
                                    fieldLabel: panel._oauthConsumerKeyTitle,
                                    name: 'oauthConsumerKey',
                                    anchor: '100%',
                                    value: record.oauthConsumerKey ? record.oauthConsumerKey : ''
                                },
                                new Ext.form.field.TextArea({
                                    fieldLabel: panel._oauthConsumerSecretTitle,
                                    name: 'oauthConsumerSecret',
                                    anchor: '100%',
                                    grow: true,
                                    growMin: 130,
                                    value: record.oauthConsumerSecret ? record.oauthConsumerSecret : ''
                                })
                            ],
                            buttons: [
                                {
                                    text: panel._saveTitle,
                                    scope: this,
                                    handler: function(btn) {
                                        var form = btn.up('form').getForm();
                                        var values = form.getValues();

                                        Ext.Ajax.request({
                                            url: url + 'ticketsystem/save',
                                            params: values,
                                            scope: this,
                                            success: function(response) {
                                                window.close();
                                                showNotification(panel._successTitle, panel._ticketSystemSavedTitle, true);
                                            },
                                            failure: function(response) {
                                                /*
                                                 * If responsetext is less than 200 chars long (means not an exception
                                                 * stack trace), use responsetext. If not, show common help/error text
                                                 */
                                                var message = response.responseText.length < 200
                                                    ? response.responseText
                                                    : panel._seriousErrorTitle;
                                                showNotification(panel._errorTitle, message, false);
                                            }
                                        });
                                    }
                                }
                            ]
                        })
                    ]
                });

                window.show();
            },
            deleteTicketSystem: function(record) {
                var grid = this;
                var id = parseInt(record.id);
                Ext.Msg.confirm('Achtung', 'Wirklich löschen?<br />' + record.name, function(btn) {
                    if (btn == 'yes') {
                        Ext.Ajax.request({
                            url: url + 'ticketsystem/delete',
                            params: {
                                id: id
                            },
                            scope: this,
                            success: function(response) {
                                grid.refresh();
                            },
                            failure: function(response) {
                                var data = Ext.decode(response.responseText);
                                showNotification(grid._errorTitle, data.message, false);
                            }
                        });
                    }
                });
            },
            refresh: function() {
                this.store.load();
                this.getView().refresh();
            }
        });


        var activityGrid = Ext.create('Ext.grid.Panel', {
            store: this.activityStore,
            columns: [
                {
                    header: this._nameTitle,
                    dataIndex: 'name',
                    flex: 1,
                    field: {
                        xtype: 'textfield'
                    }
                }, {
                    header: this._needsTicketTitle,
                    dataIndex: 'needsTicket',
                    field: {
                        xtype: 'checkbox'
                    },
                    renderer: function(value) {
                        return renderCheckbox(value);
                    }
                }, {
                    header: this._factorTitle,
                    dataIndex: 'factor',
                    flex: 1,
                    field: {
                        xtype: 'number'
                    }
                }
            ],
            tbar: [
                {
                    text: this._addActivityTitle,
                    iconCls: 'icon-add',
                    scope: this,
                    handler: function() {
                        activityGrid.editActivity();
                    }
                }, {
                    text: this._refreshTitle,
                    iconCls: 'icon-refresh',
                    scope: this,
                    handler: function() {
                        activityGrid.refresh();
                    }
                }
            ],
            listeners: {
                /* Right-click menu */
                itemcontextmenu: function(grid, record, item, index, event, options) {
                    event.stopEvent();

                    var contextMenu = Ext.create('Ext.menu.Menu', {
                        items: [
                            {
                                text: panel._editTitle,
                                iconCls: 'icon-edit',
                                scope: this,
                                handler: function() {
                                    this.editActivity(record.data);
                                }
                            }, {
                                text: panel._deleteTitle,
                                iconCls: 'icon-delete',
                                scope: this,
                                handler: function() {
                                    this.deleteActivity(record.data);
                                }
                            }
                        ]
                    });

                    contextMenu.showAt(event.xy);
                }
            },
            editActivity: function(record) {
                record = record || {};

                var window = Ext.create('Ext.window.Window', {
                    title: panel._editActivityTitle,
                    modal: true,
                    width: 400,
                    layout: 'fit',
                    id: 'edit-activity-window',
                    listeners: {
                        destroy: {
                            scope: this,
                            fn: function() {
                                this.refresh();
                            }
                        }
                    },
                    items: [
                        new Ext.form.Panel({
                            bodyPadding: 5,
                            defaultType: 'textfield',
                            items: [
                                new Ext.form.field.Hidden({
                                    name: 'id',
                                    value: record.id ? record.id : 0
                                }), {
                                    fieldLabel: panel._nameTitle,
                                    name: 'name',
                                    anchor: '100%',
                                    value: record.name ? record.name : ''
                                },
                                new Ext.form.field.Checkbox({
                                    fieldLabel: panel._needsTicketTitle,
                                    name: 'needsTicket',
                                    inputValue: 1,
                                    checked: record.needsTicket ? record.needsTicket : 0
                                }),
                                new Ext.form.field.Number({
                                    fieldLabel: panel._factorTitle,
                                    anchor: '100%',
                                    name: 'factor',
                                    value: record.factor ? record.factor : 1
                                })
                            ],
                            buttons: [
                                {
                                    text: panel._saveTitle,
                                    scope: this,
                                    handler: function(btn) {
                                        var form = btn.up('form').getForm();
                                        var values = form.getValues();

                                        Ext.Ajax.request({
                                            url: url + 'activity/save',
                                            params: values,
                                            scope: this,
                                            success: function(response) {
                                                window.close();
                                                showNotification(panel._successTitle, panel._activitySavedTitle, true);
                                            },
                                            failure: function(response) {
                                                /*
                                                 * If responsetext is less than 200 chars long (means not an exception
                                                 * stack trace), use responsetext. If not, show common help/error text
                                                 */
                                                var message = response.responseText.length < 200
                                                    ? response.responseText
                                                    : panel._seriousErrorTitle;
                                                showNotification(panel._errorTitle, message, false);
                                            }
                                        });
                                    }
                                }
                            ]
                        })
                    ]
                });

                window.show();
            },
            deleteActivity: function(record) {
                var grid = this;
                var id = parseInt(record.id);
                Ext.Msg.confirm('Achtung', 'Wirklich löschen?<br />' + record.name, function(btn) {
                    if (btn == 'yes') {
                        Ext.Ajax.request({
                            url: url + 'activity/delete',
                            params: {
                                id: id
                            },
                            scope: this,
                            success: function(response) {
                                grid.refresh();
                            },
                            failure: function(response) {
                                var data = Ext.decode(response.responseText);
                                showNotification(grid._errorTitle, data.message, false);
                            }
                        });
                    }
                });
            },
            refresh: function() {
                this.store.load();
                this.getView().refresh();
            }
        });


        var contractGrid = Ext.create('Ext.grid.Panel', {
            userStore: this.userStore,
            store: this.contractStore,
            columns: [
                {
                    header: this._nameTitle,
                    dataIndex: 'user_id',
                    flex: 1,
                    field: {
                        xtype: 'textfield',
                        lazyRender: true,
                        queryMode: 'local',
                        displayField: 'name',
                        valueField: 'user_id',
                        anchor: '100%'
                    },
                    renderer: function(id) {
                        var record = this.userStore.getById(id);
                        return record ? record.get('username') : id;
                    }
                }, {
                    header: this._startTitle,
                    dataIndex: 'start',
                    flex: 1,
                    renderer: Ext.util.Format.dateRenderer('Y-m-d')
                }, {
                    header: this._endTitle,
                    dataIndex: 'end',
                    flex: 1,
                    renderer: Ext.util.Format.dateRenderer('Y-m-d')
                }
            ],
            tbar: [
                {
                    text: this._addContractTitle,
                    iconCls: 'icon-add',
                    scope: this,
                    handler: function() {
                        contractGrid.editContract();
                    }
                }, {
                    text: this._refreshTitle,
                    iconCls: 'icon-refresh',
                    scope: this,
                    handler: function() {
                        contractGrid.refresh();
                    }
                }
            ],
            listeners: {
                /* Right-click menu */
                itemcontextmenu: function(grid, record, item, index, event, options) {
                    event.stopEvent();

                    var contextMenu = Ext.create('Ext.menu.Menu', {
                        items: [
                            {
                                text: panel._editTitle,
                                iconCls: 'icon-edit',
                                scope: this,
                                handler: function() {
                                    this.editContract(record.data);
                                }
                            }, {
                                text: panel._deleteTitle,
                                iconCls: 'icon-delete',
                                scope: this,
                                handler: function() {
                                    this.deleteContract(record.data);
                                }
                            }
                        ]
                    });

                    contextMenu.showAt(event.xy);
                }
            },
            editContract: function(record) {
                var editUserStore = Ext.create('Netresearch.store.AdminUsers', {
                    autoLoad: false
                });

                editUserStore.load();

                record = record || {};

                var window = Ext.create('Ext.window.Window', {
                    title: panel._editContractTitle,
                    modal: true,
                    width: 400,
                    layout: 'fit',
                    id: 'edit-contact-window',
                    listeners: {
                        destroy: {
                            scope: this,
                            fn: function() {
                                this.refresh();
                            }
                        }
                    },
                    items: [
                        new Ext.form.Panel({
                            bodyPadding: 5,
                            defaultType: 'textfield',
                            items: [
                                new Ext.form.field.Hidden(
                                    {
                                        name: 'id',
                                        value: record.id ? record.id : 0
                                    }
                                ),
                                new Ext.form.ComboBox({
                                    fieldLabel: panel._userTitle,
                                    name: 'user_id',
                                    store: editUserStore,
                                    queryMode: 'local',
                                    displayField: 'username',
                                    valueField: 'id',
                                    multiSelect: false,
                                    typeAhead: true,
                                    triggerAction: 'all',
                                    anchor: '100%',
                                    value: record.user_id ? record.user_id : ''
                                }),
                                new Ext.form.field.Date({
                                    id: 'start',
                                    fieldLabel: panel._startDateTitle,
                                    name: 'start',
                                    format: 'Y-m-d',
                                    value: record.start ? record.start : 0
                                }),
                                new Ext.form.field.Date({
                                    id: 'end',
                                    fieldLabel: panel._endDateTitle,
                                    name: 'end',
                                    format: 'Y-m-d',
                                    value: record.end ? record.end : 0
                                }),
                                new Ext.form.field.Number({
                                    id: 'hours_0',
                                    fieldLabel: panel._hours0Title,
                                    name: 'hours_0',
                                    value: record.hours_0 ? record.hours_0 : 0
                                }),
                                new Ext.form.field.Number({
                                    id: 'hours_1',
                                    fieldLabel: panel._hours1Title,
                                    name: 'hours_1',
                                    value: record.hours_1 ? record.hours_1 : 8
                                }),
                                new Ext.form.field.Number({
                                    id: 'hours_2',
                                    fieldLabel: panel._hours2Title,
                                    name: 'hours_2',
                                    value: record.hours_2 ? record.hours_2 : 8
                                }),
                                new Ext.form.field.Number({
                                    id: 'hours_3',
                                    fieldLabel: panel._hours3Title,
                                    name: 'hours_3',
                                    value: record.hours_3 ? record.hours_3 : 8
                                }),
                                new Ext.form.field.Number({
                                    id: 'hours_4',
                                    fieldLabel: panel._hours4Title,
                                    name: 'hours_4',
                                    value: record.hours_4 ? record.hours_4 : 8
                                }),
                                new Ext.form.field.Number({
                                    id: 'hours_5',
                                    fieldLabel: panel._hours5Title,
                                    name: 'hours_5',
                                    value: record.hours_5 ? record.hours_5 : 8
                                }),
                                new Ext.form.field.Number({
                                    id: 'hours_6',
                                    fieldLabel: panel._hours6Title,
                                    name: 'hours_6',
                                    value: record.hours_6 ? record.hours_6 : 0
                                })
                            ],
                            buttons: [
                                {
                                    text: panel._saveTitle,
                                    scope: this,
                                    handler: function(btn) {
                                        var form = btn.up('form').getForm();
                                        var values = form.getValues();
                                        Ext.Ajax.request({
                                            url: url + 'contract/save',
                                            params: values,
                                            scope: this,
                                            success: function(response) {
                                                window.close();
                                                showNotification(panel._successTitle, panel._contractSavedTitle, true);
                                            },
                                            failure: function(response) {
                                                /*
                                                 * If responsetext is less than 200 chars long (means not an exception
                                                 * stack trace), use responsetext. If not, show common help/error text
                                                 */
                                                var message = response.responseText.length < 200
                                                    ? response.responseText
                                                    : panel._seriousErrorTitle;
                                                showNotification(panel._errorTitle, message, false);
                                            }
                                        });
                                    }
                                }
                            ]
                        })
                    ]
                });

                window.show();
            },
            deleteContract: function(record) {
                var grid = this;
                var id = parseInt(record.id);
                Ext.Msg.confirm('Achtung', 'Wirklich löschen?<br />' + record.name, function(btn) {
                    if (btn == 'yes') {
                        Ext.Ajax.request({
                            url: url + 'contract/delete',
                            params: {
                                id: id
                            },
                            scope: this,
                            success: function(response) {
                                grid.refresh();
                            },
                            failure: function(response) {
                                var data = Ext.decode(response.responseText);
                                showNotification(grid._errorTitle, data.message, false);
                            }
                        });
                    }
                });
            },
            refresh: function() {
                this.userStore.load();
                this.store.load();
                this.getView().refresh();
            }
        });

        /* Create container panels for grids */
        var customerPanel = Ext.create('Ext.panel.Panel', {
            layout: 'fit',
            frame: true,
            title: this._customerManagementTitle,
            collapsible: false,
            width: '100%',
            margin: '0 0 10 0',
            items: [ customerGrid ]
        });

        var projectPanel = Ext.create('Ext.panel.Panel', {
            layout: 'fit',
            frame: true,
            title: this._projectManagementTitle,
            collapsible: false,
            width: '100%',
            margin: '0 0 10 0',
            items: [ projectGrid ]
        });

        var userPanel = Ext.create('Ext.panel.Panel', {
            layout: 'fit',
            frame: true,
            title: this._userManagementTitle,
            collapsible: false,
            width: '100%',
            margin: '0 0 10 0',
            items: [ userGrid ]
        });

        var teamPanel = Ext.create('Ext.panel.Panel', {
            layout: 'fit',
            frame: true,
            title: this._teamManagementTitle,
            collapsible: false,
            width: '100%',
            margin: '0 0 10 0',
            items: [ teamGrid ]
        });

        var presetPanel = Ext.create('Ext.panel.Panel', {
            layout: 'fit',
            frame: true,
            title: this._presetManagementTitle,
            collapsible: false,
            width: '100%',
            margin: '0 0 10 0',
            items: [ presetGrid ]
        });

        var ticketSystemPanel = Ext.create('Ext.panel.Panel', {
            layout: 'fit',
            frame: true,
            title: this._ticketSystemManagementTitle,
            collapsible: false,
            width: '100%',
            margin: '0 0 10 0',
            items: [ ticketSystemGrid ]
        });

        var activityPanel = Ext.create('Ext.panel.Panel', {
            layout: 'fit',
            frame: true,
            title: this._activityManagementTitle,
            collapsible: false,
            width: '100%',
            margin: '0 0 10 0',
            items: [ activityGrid ]
        });

        var contractPanel = Ext.create('Ext.panel.Panel', {
            layout: 'fit',
            frame: true,
            title: this._contractManagementTitle,
            collapsible: false,
            width: '100%',
            margin: '0 0 10 0',
            items: [ contractGrid ]
        });

        var config = {
            title: this._tabTitle,
            items: [ customerPanel, projectPanel, userPanel, teamPanel, presetPanel, ticketSystemPanel, activityPanel, contractPanel ]
        };

        /* Apply config */
        Ext.applyIf(this, config);
        this.callParent();
    },

    refreshStores: function () {
        this.teamStore.load();
        this.userStore.load();
        this.customerStore.load();
        this.projectStore.load();
        this.activityStore.load();
        this.ticketSystemStore.load();
        this.presetStore.load();
        this.contractStore.load();
    }
});

/*
 * Render image representation of a checkbox instead of 1 and 0
 */
function renderCheckbox(val) {
    var checkedImg = '/bundles/netresearchtimetracker/js/ext-js/resources/themes/images/default/menu/checked.gif';
    var uncheckedImg = '/bundles/netresearchtimetracker/js/ext-js/resources/themes/images/default/menu/unchecked.gif';
    var result = '<div style="text-align:center;height:13px;overflow:visible"><img style="vertical-align:-3px" src="'
        + (val ? checkedImg : uncheckedImg)
        + '" /></div>';

    return result;
}


if ((undefined != settingsData) && (settingsData['locale'] == 'de')) {
    Ext.apply(Netresearch.widget.Admin.prototype, {
        _tabTitle: 'Administration',
        _nameTitle: 'Name',
        _teamsTitle: 'Teams',
        _activeTitle: 'Aktiv',
        _globalTitle: 'Global',
        _addCustomerTitle: 'Neuer Kunde',
        _editTitle: 'Bearbeiten',
        _editCustomerTitle: 'Kunde bearbeiten',
        _forAllTeamsTitle: 'für alle Teams',
        _saveTitle: 'Speichern',
        _deleteTitle: 'Löschen',
        _seriousErrorTitle: ' Ein schwerer Fehler ist aufgetreten. Mehr Details gibts im Firebug/in den Chrome Developer Tools.',
        _customerTitle: 'Kunde',
        _ticketPrefixTitle: 'Ticket-Präfix',
        _ticketPrefixTitleHelp: 'Mehrere können kommasepariert angegeben werden',
        _ticketNumberTitle: 'Ticketnummer',
        _ticketNumberTitleHelp: 'Anstelle des Ticket-Präfix. Aufgaben in Epics und Unteraufgaben werden mit reingezählt. Mehrere mit Komma trennen.',
        _ticketSystemTitle: 'Ticket-System',
        _internalJiraTicketSystem: 'internal JIRA Ticket-System',
        _projectTitle: 'Projekt',
        _addProjectTitle: 'Neues Projekt',
        _editProjectTitle: 'Projekt bearbeiten',
        _projectSubticketsTitle: 'Bekannte Untertickets',
        _projectSubticketsSyncTitle: 'Untertickets synchronisieren',
        _subticketSyncFinishedTitle: 'Untertickets wurden von Jira synchronisiert.',
        _forAllCustomersTitle: 'für alle Kunden',
        _userNameTitle: 'Username',
        _abbreviationTitle: 'Kürzel',
        _typeTitle: 'Typ',
        _addUserTitle: 'Neuer Nutzer',
        _editUserTitle: 'Nutzer bearbeiten',
        _languageTitle: 'Sprache',
        _developerTitle: 'Entwickler',
        _projectManagerTitle: 'Projektleiter',
        _controllingTitle: 'Controlling',
        _teamTitle: 'Team',
        _teamLeadTitle: 'Teamleiter',
        _addTeamTitle: 'Neues Team',
        _editTeamTitle: 'Team bearbeiten',
        _teamSavedTitle: 'Das Team wurde erfolgreich gespeichert',
        _customerManagementTitle: 'Kunden',
        _projectManagementTitle: 'Projekte',
        _userManagementTitle: 'Nutzer',
        _teamManagementTitle: 'Teams',
        _presetManagementTitle: 'Masseneintragungsvorlagen',
        _presetSavedTitle: 'Die Vorlage wurde erfolgreich gespeichert.',
        _addPresetTitle: 'Neue Vorlage',
        _editPresetTitle: 'Vorlage bearbeiten',
        _activityTitle: 'Tätigkeit',
        _descriptionTitle: 'Beschreibung',
        _ticketSystemManagementTitle: 'Ticket-Systeme',
        _ticketSystemSavedTitle: 'Das Ticket-System wurde erfolgreich gespeichert.',
        _addTicketSystemTitle: 'Neues Ticket-System',
        _urlTitle: 'URL',
        _ticketUrlTitle: 'Ticket URL',
        _ticketUrlHint: '"%s" als Platzhalter für Ticketnamen',
        _timebookingTitle: 'Zeitbuchung',
        _loginTitle: 'Login',
        _passwordTitle: 'Passwort',
        _publicKeyTitle: 'Public Key',
        _privateKeyTitle: 'Private Key',
        _errorsTitle: 'Fehler',
        _errorTitle: 'Fehler',
        _successTitle: 'Erfolg',
        _estimationTitle: 'Geschätzte Dauer',
        _internalJiraProjectKey: 'internal JIRA Projekt Key',
        _offerTitle: 'Angebot',
        _billingTitle: 'Abrechnung',
        _costCenterTitle: 'Kostenstelle',
        _projectLeadTitle: 'Projekt-Leitung',
        _technicalLeadTitle: 'Technische Leitung',
        _max31CharactersTitle: 'Hier sind maximal 31 Zeichen erlaubt',
        _additionalInformationFromExternal: 'weitere Informationen aus (externen) Ticket-System beziehen',
        _activityManagementTitle: 'Tätigkeiten',
        _addActivityTitle: 'Neue Tätigkeit',
        _editActivityTitle: 'Tätigkeit bearbeiten',
        _activitySavedTitle: 'Die Tätigkeit wurde erfolgreich gespeichert.',
        _needsTicketTitle: 'Benötigt Ticket',
        _factorTitle: 'Faktor',
        _oauthConsumerKeyTitle: 'OAuth Consumer-Key',
        _oauthConsumerSecretTitle: 'OAuth Consumer-Secret',
        _refreshTitle: 'Aktualisieren',
        _startTitle: 'Beginn',
        _endTitle: 'Ende',
        _addContractTitle: 'Vertrag hinzufügen',
        _contractManagementTitle: 'Verträge',
        _editContractTitle: 'Vertrag bearbeiten',
        _contractSavedTitle: 'Vertrag gespeichert',
        _startDateTitle: 'Vertragsbeginn',
        _endDateTitle: 'Vertragsende',
        _userTitle: 'Benutzer',
        _hours0Title: 'Sonntag (h)',
        _hours1Title: 'Montag (h)',
        _hours2Title: 'Dienstag (h)',
        _hours3Title: 'Mittwoch (h)',
        _hours4Title: 'Donnerstag (h)',
        _hours5Title: 'Freitag (h)',
        _hours6Title: 'Samstag (h)',
    });
}
