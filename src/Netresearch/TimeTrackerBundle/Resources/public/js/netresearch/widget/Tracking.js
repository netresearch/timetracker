Ext.define('Netresearch.widget.Tracking', {

    extend: 'Ext.grid.Panel',

    requires: [
        'Netresearch.store.Entries',
        'Netresearch.store.Customers',
        'Netresearch.store.Projects',
        'Netresearch.store.Activities',
        'Netresearch.store.AdminUsers',
        'Ext.ux.window.Notification'
    ],

    debug: false,

    /* Create stores */
    customerStore: Ext.create('Netresearch.store.Customers'),
    projectStore: Ext.create('Netresearch.store.Projects'),
    activityStore: Ext.create('Netresearch.store.Activities'),
    userStore: Ext.create('Netresearch.store.AdminUsers'),
    ticketSystemStore: Ext.create('Netresearch.store.TicketSystems'),
    startTime : null,

    /* Strings */
    _tabTitle: 'Time Tracking',
    _dateTitle: 'Date',
    _startTitle: 'Start',
    _endTitle: 'End',
    _ticketTitle: 'Ticket',
    _customerTitle: 'Customer',
    _projectTitle: 'Project',
    _activityTitle: 'Activity',
    _descriptionTitle: 'Description',
    _durationTitle: 'Duration',
    _infoTitle: 'Info',
    _continueTitle: 'Continue',
    _deleteTitle: 'Delete',
    _addTitle : 'Add Entry',
    _refreshTitle: 'Refresh',
    _exportTitle: 'Export',
    _daysTitle: 'Days',
    _ongoingSaveTitle: 'A previous entry is being saved.',
    _attentionTitle: 'Attention',
    _confirmDeleteTitle: 'Do really want to delete?',
    _overviewTitle: 'Overview',
    _entriesTitle: 'Entries',
    _totalDurationTitle: 'Total Duration',
    _ownDurationTitle: 'My Duration',
    _plannedTitle: 'Planned',
    _statusTitle: 'Status',
    _minDurationTitle: 'An entry must have a minimal duration of 1 minute.',
    _customerProjectMismatchTitle: 'Customer and project do not match together.',
    _chooseOtherCustomerOrProjectTitle: 'Please choose another customer or project.',
    _noActivityGivenTitle: 'No activity given',
    _noTicketGivenTitle: 'No ticket given',
    _informationRetrievalErrorTitle: 'Error retrieving information',
    _sessionExpiredTitle: 'The session has expired - please login again.',
    _saveErrorTitle: 'Error during save',
    _possibleErrorsTitle: 'Possible errors',
    _loginAgainTitle: 'Please logout and login to the time tracker again.',
    _timesOverlapTitle: 'Start and end overlap.',
    _checkTimesTitle: 'Please check start und end of your entry.',
    _fieldsMissingTitle: 'Fields are missing.',
    _checkFieldsTitle: 'Please check customer, project and activity.',
    _errorTitle: 'Error',
    _successTitle: 'Success',

    /* Initialize tracking widget */
    initComponent: function()
    {
        this.on('render', this.refresh, this);
        this.startTime = this.roundTime(this.getNewDate());

        const entryStore = Ext.create('Netresearch.store.Entries');
        const grid = this;
        entryStore.on("load", function() { grid.selectRow(0); });

        const config = {
            title: this._tabTitle,
            autoRender: true,
            store: entryStore,
            stateful: false,
            stateId: 'trackingGrid',
            sortableColumns: false,
            viewConfig: {
                /* Set colors for row:
                 * - green: day switch
                 * - grey: break
                 * - red: overlapping times
                 */
                getRowClass: function(record, index) {
                    // new algorithm using backend
                    switch (parseInt(record.data.class)) {
                        case 1: return '';
                        case 2: return 'night-row-bottom';
                        case 4: return 'pause-row-bottom';
                        case 8: return 'overlap-row-bottom';
                    }

                    // old algorithm in the frontend
                    const store = this.getStore();
                    const maxIndex = store.getCount() - 1;

                    // skip unsaved entries
                    if (('undefined' === typeof(record.index))
                            || (undefined === record.data.id)
                            || (null == record.data.id)
                            || (record.data.id < 1)) {
                        return 'unsaved';
                    }

                    // skip last entry
                    if (maxIndex <= record.index) {
                        return '';
                    }

                    // search neighbour entry
                    let isFirst = true;
                    let isBreak = true;

                    for (let k = 0; k <= maxIndex; k++) {
                        const comparison = store.getAt(k);

                        // skip unsaved comparisons
                        if ((undefined === comparison) || (null == comparison) || (undefined === comparison.data.id) || (null == comparison.data.id) || (comparison.data.id < 1)) {
                            continue;
                        }

                        // do not compare entries with themselves
                        if (comparison.data.id === record.data.id) {
                            continue;
                        }

                        // skip entries of different days, they have no impact
                        if (comparison.data.date.toString() !== record.data.date.toString()) {
                            continue;
                        }

                        // immediately escalate on overlapping entries
                        if ((comparison.data.start.toString() < record.data.start.toString())
                        && (comparison.data.start.toString() < record.data.end.toString())
                        && (comparison.data.end.toString() > record.data.start.toString())) {
                            return 'overlap-row-bottom';
                        }

                        if ((isFirst) && (comparison.data.start.toString() < record.data.start.toString())) {
                            isFirst = false;
                        }

                        if ((isBreak) && (comparison.data.end.toString() === record.data.start.toString())) {
                            isBreak = false;
                        }

                    }

                    if (isFirst) {
                        return 'night-row-bottom';
                    }

                    if (isBreak) {
                        return 'pause-row-bottom';
                    }

                    return '';
                }
            },
            columns: [
                {
                    header: 'Id',
                    dataIndex: 'id',
                    sortable: false,
                    hidden: true
                }, {
                    header: this._dateTitle,
                    dataIndex: 'date',
                    sortable: false,
                    width: 90,
                    field: {
                        format: 'd.m.Y',
                        editable: true,
                        submitFormat: 'd.m.Y',
                        startDay: 1,
                        xtype: 'datefield',
                        typeAhead: true,
                        typeAheadDelay: 0,
                        triggerAction: 'all',
                        selectOnTab: true,
                        selectOnFocus: true
                    },
                    renderer: this.formatDate
                }, {
                    header: this._startTitle,
                    dataIndex: 'start',
                    sortable: false,
                    width: 60,
                    field: {
                        xtype: 'timefield',
                        format: 'H:i',
                        increment: 1440,
                        hideTrigger: true,
                        selectOnTab: true,
                        selectOnFocus: true
                    },
                    renderer: this.formatTime
                }, {
                    header: this._endTitle,
                    dataIndex: 'end',
                    sortable: false,
                    width: 60,
                    field: {
                        xtype: 'timefield',
                        format: 'H:i',
                        increment: 1440,
                        hideTrigger: true,
                        selectOnTab: true,
                        selectOnFocus: true
                    },
                    renderer: this.formatTime
                }, {
                    header: this._ticketTitle,
                    dataIndex: 'ticket',
                    sortable: false,
                    width: 110,
                    field: {
                        xtype: 'textfield',
                        typeAhead: true,
                        typeAheadDelay: 0,
                        triggerAction: 'all',
                        selectOnTab: true,
                        selectOnFocus: true,
                        listeners: {
                            scope: this,
                            blur: function(field, options) {
                                const selection = this.getSelectionModel().getSelection();
                                if (selection.length < 1) {
                                    return;
                                }

                                // Map tickets to projects and customers
                                const ticket = selection[0].get('ticket').toUpperCase();
                                selection[0].data.ticket = ticket;

                                if ((undefined === ticket) || (null === ticket) || (0 === ticket.length)) {
                                    return;
                                }

                                // new method inside JS
                                const result = this.mapTicketToProject(selection[0].data.ticket);
                                if (! result)
                                    return;

                                if ((result['customer'] === 0) && (result['id'] === 0))
                                    return;

                                let editStartColumn = 6;
                                let edited = false;
                                if (0 < parseInt(result['customer'])) {
                                    editStartColumn = 7;
                                    if (selection[0].data.customer !== parseInt(result['customer'])) {
                                        selection[0].data.customer = parseInt(result['customer']);
                                        selection[0].data.project = 0;
                                        edited = true;
                                    }
                                }

                                if (0 < parseInt(result['id'])) {
                                    if ((editStartColumn === 7) && (result['sure'] !== false))
                                        editStartColumn = 7;
                                    if (selection[0].data.project !== parseInt(result['id'])) {
                                        selection[0].data.project = parseInt(result['id']);
                                        edited = true;
                                    }
                                }

                                this.clearProjectStore();
                                selection[0].commit();

                                if (edited) {
                                    this.editingPlugin.startEditByPosition({row: this.getStore().indexOf(selection[0]), column: editStartColumn});
                                }

                                return;
                            }
                        }
                    },
                    renderer: function(ticket) {
                        /* Display link to bugs.nr, if Ticket is not empty */
                        if ((!ticket)||(ticket === "")||(ticket === "-")) {
                            return '-';
                        }
                        const str = ticket.replace(/ /g,'').toUpperCase();
                        const ticketUrl = this.getTicketsystemUrlByTicket(str);
                        return '<a href="' + ticketUrl + '" target="_new">'+ str  +'</a>';
                    }
                }, {
                    header: this._customerTitle,
                    dataIndex: 'customer',
                    sortable: false,
                    width: 175,
                    field: {
                        xtype: 'combobox',
                        typeAhead: true,
                        typeAheadDelay: 0,
                        triggerAction: 'all',
                        selectOnTab: true,
                        selectOnFocus: true,
                        forceSelection: true,
                        lazyRender: true,
                        store: this.customerStore,
                        listClass: 'x-combo-list-small',
                        queryMode: 'local',
                        displayField: 'name',
                        valueField: 'id',
                        anchor: '100%',
                        listeners: {
                            scope: this,
                            focus: function(field, value) {
                                this.customerStore.load(true);
                            },
                            blur: function(field, options) {
                                this.customerStore.load(false);
                            }
                        }
                    },
                    renderer: function(id) {
                        const record = this.customerStore.getById(id);
                        return record ? record.get('name') : id;
                    }
                }, {
                    header: this._projectTitle,
                    dataIndex: 'project',
                    sortable: false,
                    width: 250,
                    field: {
                        xtype: 'combobox',
                        typeAhead: true,
                        typeAheadDelay: 0,
                        triggerAction: 'all',
                        selectOnTab: true,
                        selectOnFocus: true,
                        forceSelection: true,
                        lazyRender: true,
                        store: this.projectStore,
                        listClass: 'x-combo-list-small',
                        queryMode: 'local',
                        displayField: 'name',
                        valueField: 'id',
                        anchor: '100%',
                        listeners: {
                            scope: this,
                            focus: function(field, value) {
                                var ticket = this.getSelectedField('ticket');
                                if ((ticket == "") || (ticket == "-")) {
                                    ticket = null;
                                }

                                this.projectStore.loadData(projectsData, this.getSelectedField('customer'), ticket, true);
                            },
                            blur: function(field, options) {
                                // this.clearProjectStore();
                            }
                        }
                    },
                    renderer: function(id) {
                        const record = this.projectStore.getById(id);
                        return record ? record.get('name') : id;
                    }
                }, {
                    header: this._activityTitle,
                    dataIndex: 'activity',
                    sortable: false,
                    width: 125,
                    field: {
                        xtype: 'combobox',
                        typeAhead: true,
                        typeAheadDelay: 0,
                        triggerAction: 'all',
                        selectOnTab: true,
                        selectOnFocus: true,
                        forceSelection: true,
                        lazyRender: true,
                        store: this.activityStore,
                        listClass: 'x-combo-list-small',
                        queryMode: 'local',
                        displayField: 'name',
                        valueField: 'id',
                        anchor: '100%'
                    },
                    renderer: function(id) {
                        const record = this.activityStore.getById(id);
                        return record ? record.get('name') : id;
                    }
                }, {
                    header: this._descriptionTitle,
                    dataIndex: 'description',
                    sortable: false,
                    flex: 1, // means dynamic width
                    field: {
                        xtype: 'textfield',
                        typeAhead: true,
                        typeAheadDelay: 0,
                        triggerAction: 'all',
                        selectOnFocus: true,
                        selectOnTab: true
                    },
                    renderer: function(text) {
                        text = new String('' + text);
                        text = text.replace(/&/g, '&amp;')
                            .replace(/</g, '&lt;')
                            .replace(/>/g, '&gt;')
                            .replace(/"/g, '&quot;');

                        // replace valid ticketnames with links according to ticket_systems.ticketurl
                        const arr = text.match(/([A-Z]+(::[A-Z0-9]+)?-[0-9]+)/ig) || [];
                        for (let i = 0; i < arr.length; i++) {
                            const ticketUrl = this.getTicketsystemUrlByTicket(arr[i]);
                            text = text.split(arr[i]).join('<a href="' + ticketUrl + '" target="_new">' + arr[i] + '<\/a>');
                        }

                        return text;
                    }
                }, {
                    header: this._durationTitle,
                    dataIndex: 'duration',
                    sortable: false,
                    width: 50,
                    renderer: this.formatTime
                }, {
                    header: 'ext. ticket',
                    dataIndex: 'extTicket',
                    sortable: false,
                    width: 110,
                    renderer: function(extTicket) {
                        if (!extTicket) {
                            return;
                        }
                        const text = new String('' + extTicket);
                        return text
                            .replace(/&/g, '&amp;')
                            .replace(/</g, '&lt;')
                            .replace(/>/g, '&gt;')
                            .replace(/"/g, '&quot;')
                            .replace(/([A-Z]+(::[A-Z0-9]+)?-[0-9]+)/ig, '<a href="http:\/\/bugs.nr/$1" target="_new">$1<\/a>');
                    }
                }
            ],
            /* Topbar */
            tbar: [
                {
                    text: this._addTitle,
                    iconCls: 'icon-add',
                    tooltip: 'Shortcut (Alt + a)',
                    scope: this,
                    handler: function() {
                        this.addInlineEntry();
                    }
                }, {
                    text: this._refreshTitle,
                    iconCls: 'icon-refresh',
                    tooltip: 'Shortcut (Alt + r)',
                    scope: this,
                    handler: function() {
                        this.refresh();
                    }
                }, {
                    text: this._exportTitle,
                    iconCls: 'icon-export',
                    tooltip: 'Shortcut (Alt + x)',
                    scope: this,
                    handler: function() {
                        this.exportEntries();
                    }
                }, {
                    xtype: 'combobox',
                    fieldLabel: this._daysTitle,
                    labelAlign: 'right',
                    store: Ext.create('Ext.data.ArrayStore', {
                        fields: ['days'],
                        data: [
                            ['1'],
                            ['3'],
                            ['7'],
                            ['35']
                        ]
                    }),
                    queryMode: 'local',
                    allowBlank: false,
                    stateId: 'daysSelect',
                    displayField: 'days',
                    valueField: 'days',
                    listeners: {
                        afterrender: function(field, eOpts) {
                            /* Set interval dropdown default value to 3 days */
                            var defaultValue = '3';
                            field.setValue(defaultValue);
                            this.days = defaultValue;
                        },
                        scope: this,
                        change: function(field, newValue, oldValue, eOpts) {
                            /* Reload if interval dropdown has changed */
                            if (undefined !== oldValue && newValue !== oldValue && '' !== newValue) {
                                // reload
                                const proxy = this.getStore().getProxy();
                                proxy.url = proxy.url.replace(/\d+$/, newValue);
                                this.days = newValue;
                                this.refresh();
                            }
                        }
                    }
                }
            ],
            listeners: {
                itemcontextmenu: function(grid, record, item, index, event, options) {
                    event.stopEvent();

                    /* Right-click menu */
                    const contextMenu = Ext.create('Ext.menu.Menu', {
                        items: [
                            {
                                text: this._infoTitle,
                                iconCls: 'icon-info',
                                scope: this,
                                handler: function() {
                                    this.showInfoOnSelectedEntry();
                                },
                                disabled: !(record.data.id && record.data.customer && record.data.project)
                            }, {
                                text: this._continueTitle,
                                iconCls: 'icon-add',
                                scope: this,
                                handler: function() {
                                    this.continueSelectedEntry();
                                }
                            }, {
                                text: this._deleteTitle,
                                iconCls: 'icon-delete',
                                scope: this,
                                handler: function() {
                                    this.deleteSelectedEntry();
                                }
                            }
                        ]
                    });

                    contextMenu.showAt(event.xy);
                },
                /* Refresh grid when columns are resized via drag and drop by user */
                columnresize: {
                    fn: function(container, column, width, eOpts) {
                        this.getView().refresh();
                    }
                },
                edit: function(grid, row, field, rowIndex, columnIndex) {
                    /* Display empty line on top, if specific setting is enabled */
                    if (settingsData.show_empty_line) {
                        if (0 === row.rowIdx && 0 !== row.record.data.id && 0 !== this.getStore().getAt(row.rowIdx).data.id) {
                            this.addInlineEntry();
                        }
                    }

                    const record = row.record;
                    /* Save record */
                    if (record.dirty) {
                        record.data.from = this.roundTime(record.data.from);
                        record.data.to = this.roundTime(record.data.to);

                        // Map projects to customers
                        if ((undefined !== record.data.project)
                            && (null != record.data.project)
                            && (0 !== record.data.project.length)
                            && (null === record.data.customer || 0 === record.data.customer)
                        ) {
                            const projects = projectsData['all'];
                            for (let key in projects) {
                                const value = projects[key];
                                if (value.id === record.data.project) {
                                     record.data.customer = parseInt(value.customer);
                                     record.commit();
                                     this.customerStore.load();
                                     break;
                                }
                            }
                        }

                        this.saveRecord(record);
                    }
                }
            }
        };

        Ext.applyIf(this,config);
        this.callParent();
    },

    mapTicketToProject: function(ticket) {
        const validProjects = findProjects(null, ticket);

        this.debug && console.log("Mapping ticket " + ticket);

        if ((!validProjects) || (!validProjects.length)) {
            this.debug && console.log("Mapped to no project");
            return false;
        }

        let customer = validProjects[0]['customer'];
        let id = validProjects[0]['id'];
        let sure = true;

        if (validProjects.length == 1) {
            this.debug && console.log("Mapped to customer " + customer + " and project " + " (sure, single)");
            return { customer: parseInt(customer), id: parseInt(id), sure: sure };
        }

        for (let i = 1; i < validProjects.length; i++) {
            if ((customer > 0) && (validProjects[i]['customer'] != customer))
                customer = 0;

            if ((id > 0) && (validProjects[i]['id'] != id))
                id = 0;
        }

        // If we found no unique project, lets take the last used one
        if ((parseInt(customer) > 0) && (id === 0)) {
            id = parseInt(this.findLastProjectByTicket(ticket, parseInt(customer)));
            if (! id) {
                id = parseInt(this.findLastProjectByPrefix(extractTicketPrefix(ticket), parseInt(customer)));
                sure = false;
            }
        }

        this.debug && console.log("Mapped to customer " + customer + " and project " + id + (sure ? " (sure)" : " (unsure)"));
        return { customer: parseInt(customer), id: parseInt(id), sure: sure };
    },

    /*
     * Finds the last used project number based on a ticket and customer
     */
    findLastProjectByTicket: function(ticket, customer) {
        const store = this.getStore();

        for (let i = 0; i < store.getCount() && i < 100; i++) {
            const record = store.getAt(i);
            if (parseInt(record.data.customer) != customer)
                continue;
            if (1 > parseInt(record.data.project))
                continue;
            if ((undefined != record.data.ticket) && (null != record.data.ticket) && (record.data.ticket.length > 0)) {
                if (record.data.ticket == ticket) {
                    return record.data.project;
                }
            }
        }

        return 0;
    },

    /*
     * Finds the last used project number based on a ticket prefix and customer
     */
    findLastProjectByPrefix: function(prefix, customer) {
        if (! prefix)
            return false;

        const store = this.getStore();

        for (let i = 0; i < store.getCount() && i < 100; i++) {
            const record = store.getAt(i);
            if (parseInt(record.data.customer) != customer)
                continue;
            if (1 > parseInt(record.data.project))
                continue;
            if ((undefined != record.data.ticket) && (null != record.data.ticket) && (record.data.ticket.length > 0)) {
                if (extractTicketPrefix(record.data.ticket) == prefix)
                return record.data.project;
            }
        }

        return 0;
    },

    /*
     * Returns the Ticketsystem-URL for a Ticket
     */
    getTicketsystemUrlByTicket: function(ticket) {
        let baseUrl;

        try{
            const projectMapping = this.mapTicketToProject(ticket);
            const project = this.projectStore.getById(projectMapping.id);
            const ticketSystem = this.ticketSystemStore.getById(project.get('ticket_system'));
            baseUrl = ticketSystem.get('ticketUrl');
            if (baseUrl == '') {
                throw "empty baseUrl";
            }
        } catch(err){
            baseUrl = 'http://bugs.nr/%s';
        }

        return baseUrl.split("%s").join(ticket);
    },

    /*
     * Edit selected or first entry and jump to endtime column
     */
    editSelectedEntry: function() {
        const index = this.getSelectedIndex();
        if (0 > index)
            return;

        const record = this.store.getAt(index);
        if (undefined != record)
            this.editingPlugin.startEditByPosition({row: index, column: 3});
        return;
    },

    /**
     * Show info on selected or first entry's ticket / project and customer
     */
    showInfoOnSelectedEntry: function() {
        var index = this.getSelectedIndex();
        if (0 > index)
            return;

        var record = this.store.getAt(index);
        if ((undefined != record) && (0 < parseInt(record.data.id)))
            this.getSummary(record.data.id);
    },

    /*
     * Save given record to database
     */
    saveRecord: function(record) {

        if ((null == record.data.start) || (undefined == record.data.start) || (1 > record.data.start.length)) {
            return;
        }

        if ((null == record.data.end) || (undefined == record.data.end) || (1 > record.data.end.length)) {
            return;
        }

        if (("00:00" == this.formatTime(record.data.start)) && ("00:00" == this.formatTime(record.data.end))) {
            return;
        }

        if ((record.data.description.length > 1) && (record.data.activity == null || record.data.activity == 0)) {
            showNotification(this._errorTitle, this._noActivityGivenTitle, false);
            return;
        }

        if ((0 === parseInt(record.data.project+0)) || (0 === parseInt(record.data.customer+0)) || (0 === parseInt(record.data.activity+0))) {
            return;
        }

        if (this.formatTime(record.data.start) >= this.formatTime(record.data.end)) {
            showNotification(this._errorTitle, this._minDurationTitle, false);
            return;
        }

        if ('undefined' == typeof(record.saveInProgress)) {
            // Check if customer and project are related
            var projectCheck = this.checkCustomerProjectRelation(record.data.customer, record.data.project);
            if (false == projectCheck) {
                showNotification(this._errorTitle,
                        this._customerProjectMismatchTitle
                        + '<br /><br />'
                        + this._chooseOtherCustomerOrProjectTitle
                        , false
                );
                return;
            }

            if ((true !== projectCheck) && (0 < parseInt(projectCheck))) {
                record.data.project = parseInt(projectCheck);
            }

            // reformat ticket
            record.data.ticket = record.data.ticket.replace(/ /g,'').toUpperCase();

            const grid = this;
            Ext.Ajax.request({
                url: url + 'tracking/save',
                params: record.data,
                success: function(response) {
                    record.saveInProgress=undefined;
                    var data = Ext.decode(response.responseText);

                    if (data.result) {
                        record.data.duration = new Date(0, 0, 0, 0, data.result.duration, 0, 0);
                        record.data.id = data.result.id;
                        record.data.extTicket = data.result.extTicket;
                        record.data.extTicketUrl = record.data.extTicket;
                        record.commit();
                    }
                    if (data.alert) {
                        showNotification(grid._attentionTitle, data.alert, false);
                    }

                    countTime();
                },
                failure: function(response) {
                    record.saveInProgress=undefined;
                    record.dirty = true;
                    const responseContent = JSON.parse(response.responseText);
                    showNotification(grid._errorTitle, responseContent.message, false);
                    if (typeof responseContent.forwardUrl !== 'undefined') {
                        setTimeout("window.location.href = '" + responseContent.forwardUrl + "'", 2000);
                    }
                }
            });

            record.saveInProgress = 1;
        } else {
            record.saveInProgress += 1;
            /* Prevent loop */
            if (record.saveInProgress >= 50) {
                record.saveInProgress = 1;
                showNotification(this._errorTitle, this._ongoingSaveTitle, false);
            } else {
                /* dunno */
                Ext.Function.defer(this.saveRecord, 400, this, [record]);
            }
        }
    },

    /*
     * Check if current customer has the current project
     * Display error message if not
     */
    checkCustomerProjectRelation: function(customer, project) {
        if (undefined === customer) {
            return true;
        }

        if (undefined === project) {
            return true;
        }

        if ((undefined !== customer) && (false !== customer) && (0 < parseInt(customer))) {
        } else {
            customer = 'all';
        }

        const customerProjects = projectsData[customer];
        if (! customerProjects) {
            return false;
        }

        // First search a correct customer-project relation
        if (customerProjects) {
            for (let key in customerProjects) {
                const value = customerProjects[key];
                if (value.id === project) {
                    return true;
                }
            }
        }

        // Then try to repair the project id by name

        // don't we have a customer to repair?
        if (customer === 'all') {
            return false;
        }

        // Resolve the name of the searched project
        let name = '';
        const allProjects = projectsData["all"];
        for (let key in allProjects) {
            const value = allProjects[key];
            if (value.id === project) {
                name = value.name;
            }
        }

        // Didn't we found the project ?!
        if (name === '') {
            return false;
        }

        // Now we got the project's name we try to search a common name within the customer's own projects
        for (let key in customerProjects) {
            const value = customerProjects[key];
            if (value.name === name) {
                console.log("Wrong project " + project + " for customer " + customer + ". I think you want project " + value.id + ".");
                return value.id;
            }
        }

        return false;
    },

    /*
     * Format summary report (right click -> info)
     */
    formatSummary: function(title, summary) {
        var str = "<h2>" + title + " " + summary.name + "</h2>"
                + this._entriesTitle + ": " + summary.entries
                + ", " + this._totalDurationTitle + ": " + formatDuration(summary.total, true);

        if ((undefined != summary.estimation) && (0 < summary.estimation)) {
            str += ', ' + this._plannedTitle + ': ' + formatDuration(summary.estimation, true);
            str += ', ' + this._statusTitle + ': ' + summary.quota;
        }

        str += ", " + this._ownDurationTitle + ": " + formatDuration(summary.own, true)
            + "<br/><br/>";
        return str;
    },

    /*
     * Get summary for day, week and month from database
     */
    getSummary: function(id) {
        Ext.Ajax.request({
            url: url + 'getSummary',
            params: { id: id },
            scope: this,
            success: function(response) {
                const data = Ext.decode(response.responseText);

                let dlgMessage = this.formatSummary(this._customerTitle, data.customer)
                                + this.formatSummary(this._projectTitle, data.project);

                if (data.activity.name.length > 2) {
                    dlgMessage += this.formatSummary(this._activityTitle, data.activity);
                } else {
                    dlgMessage += "<h2>" + this._activityTitle + "</h2>"
                    + "<i>" + this._noActivityGivenTitle + "</i>"
                    + "<br/><br/>";
                }

                if (data.ticket.name.length > 2) {
                    dlgMessage += this.formatSummary(this._ticketTitle, data.ticket);
                } else {
                    dlgMessage += "<h2>" + this._ticketTitle + "</h2>"
                    + "<i>" + this._noTicketGivenTitle + "</i>"
                    + "<br/><br/>";
                }

                /* Display info window with summary data */
                const grid = this;
                const infowindow = new Ext.Window({
                    title: this._overviewTitle,
                    html: dlgMessage,
                    width: 525,
                    listeners: {
                        close: function() {
                            grid.getView().el.focus();
                        }
                    }
                });
                infowindow.show();
            },
            failure: function(response) {
                handleRedirect(response,
                        this._informationRetrievalErrorTitle,
                        this._sessionExpiredTitle);
            }
        });
    },

    prolongLastEntry: function() {
        const record = this.store.getAt(0);
        if ((undefined == record) || (null == record) || (undefined == record.data) || (null == record.data))
            return;

        const date = this.roundTime(this.getNewDate());

        if (! this.isSameDay(date, record.data.date))
            return;

        record.data.end = date;
        record.dirty = true;
        this.saveRecord(record);
    },

    continueSelectedEntry: function() {
        const index = this.getSelectedIndex();
        if (0 > index)
            return;

        const record = this.store.getAt(index);
        if ((undefined == record) || (null == record) || (undefined == record.data) || (null == record.data))
            return;

        const data = {};
        data.customer = record.data.customer;
        data.project = record.data.project;
        data.activity = record.data.activity;
        data.description = record.data.description;
        data.ticket = record.data.ticket;
        data.extTicket = record.data.extTicket;
        data.extTicketUrl = record.data.extTicketUrl;
        data.date = null;
        data.start = null;
        data.end = null;
        this.addInlineEntry(data);
    },

    deleteSelectedEntry: function() {
        const index = this.getSelectedIndex();
        if (0 > index)
            return;

        const record = this.store.getAt(index);

        if (undefined == record)
            return;

        /* compose a message for the customer to re-check basic activity data */
        const grid = this;
        const duration     = this.formatTime(record.data.duration);
        const ticket       = record.data.ticket;
        const description  = record.data.description;
        let shortDescription = duration;
        if (0 < ticket.length) {
            shortDescription += ' | ' + ticket;
        }

        if (0 < parseInt(record.data.customer)) {
            shortDescription += ' | ' + this.getCustomerName(record.data.customer);
        }

        if (0 < parseInt(record.data.project)) {
            shortDescription += ' | ' + this.getProjectName(record.data.project);
        }

        if (0 < parseInt(record.data.activity)) {
            shortDescription += ' | ' + this.getActivityName(record.data.activity);
        }

        shortDescription += ' | ' + description;

        /* Display confirm message before deletion */
        Ext.Msg.confirm(this._attentionTitle, this._confirmDeleteTitle + '<br />' + shortDescription, function(btn) {
            if (btn === 'yes') {
                grid.deleteEntry(index);
            }
            grid.getView().el.focus();
        });
    },

    /*
     * Remove entry from grid and database (if already saved)
     */
    deleteEntry: function(index) {
        const record = this.store.getAt(index);
        if (undefined == record)
            return;

        // Easy deletion of unsaved records
        if (0 >= parseInt(record.data.id)) {
            this.getStore().remove(record);
            this.clearProjectStore();
            this.selectRow(index);
            this.getView().refresh();
            return;
        }

        // Delete saved records only after server deletion
        const id = parseInt(record.data.id);
        Ext.Ajax.request({
            url: url + 'tracking/delete',
            params: {
                    id: id
            },
            scope: this,
            success: function(response) {
                const data = Ext.decode(response.responseText);

                this.getStore().remove(record);
                this.clearProjectStore();
                this.selectRow(index);
                this.getView().refresh();
                if (data.alert) {
                    showNotification(grid._attentionTitle, data.alert, false);
                }
            },
            failure: function(response) {
                const data = Ext.decode(response.responseText);
                showNotification(grid._errorTitle, data.message, false);
                if (typeof data.forwardUrl != 'undefined') {
                    setTimeout("window.location.href = '" + data.forwardUrl + "'", 2000);
                }
            }
        });
    },

    /*
     * Redirect to exports action
     */
    exportEntries: function() {
        if ('undefined' == this.days) {
            this.days = 10000;
        }
        window.location.href = 'export/' + this.days;
    },

    /*
     * Refresh stores
     */
    refresh: function() {
        this.clearProjectStore();
        this.customerStore.load();
        this.activityStore.load();
        this.userStore.load();
        this.ticketSystemStore.load();
        this.getStore().load();

        this.getView().refresh();
        countTime();
    },

    /*
     * Well, round to next 5 minutes
     * 11:22 ==> 11:20
     * 11:28 ==> 11:30
     */
    round5: function(x) {
        return (x % 5) >= 2.5 ? parseInt(x / 5) * 5 + 5 : parseInt(x / 5) * 5;
    },

    /*
     * Returns rounded time object from given time object
     */
    roundTime: function(time) {
        time ? time.setMinutes(this.round5(time.getMinutes())) : '';
        return time;
    },

    /*
     * Create new date object
     */
    getNewDate: function() {
        const date = new Date();
        date.setMilliseconds(0);
        date.setSeconds(0);
        return date;
    },

    /*
     * Return given date in "d.m.Y" representation
     */
    formatDate: function(value) {
        return value ? Ext.Date.dateFormat(value, 'd.m.Y') : '';
    },

    /*
     * Return given time in "H:i" representation
     */
    formatTime: function(value) {
        return value ? Ext.Date.dateFormat(value, 'H:i') : '';
    },

    /*
     * Reload data from json var into project store (json var is set on page load by symfony output)
     */
    clearProjectStore: function() {
        this.projectStore.loadData(projectsData, null, null, false);
    },

    getSelectedIndex: function() {
        if (1 > this.store.getCount())
            return -1;

        const selection = this.getSelectionModel().getSelection();
        if (selection.length > 0)
            return this.store.indexOf(selection[0]);
        else
            return 0;
    },

    getSelectedField: function(field) {
        const selection = this.getSelectionModel().getSelection();
        if (selection.length > 0) {
            return selection[0].get(field);
        } else {
            return null;
        }
    },

    /*
     * Display informative error message if an error occurred during save process
     */
    showSaveFailure: function(response, opts) {
        let dlgMessage = '<h2>' + this._saveErrorTitle + '</h2>'
                        + '<b>' + this._possibleErrorsTitle + ':</b>'
                        + '<br />b) ' + this._timesOverlapTitle + '<br />' + this._checkTimesTitle
                        + '<br />c) ' + this._fieldsMissingTitle + '<br/>' + this._checkFieldsTitle;

        /* If response contains useful error message, use it */
        if (response && response.responseText) {
            dlgMessage = response.responseText;
        }
        showNotification(this._errorTitle, dlgMessage, false);
    },

    isSameDay : function(a, b) {
        return (a.getYear() == b.getYear())
            && (a.getMonth() == b.getMonth())
            && (a.getDate() == b.getDate());
    },

    getMinutesFromMidnight : function(a) {
        return (a.getHours() * 60 + a.getMinutes());
    },

    /*
     * Add new entry to grid (without saving it do database!)
     */
    addInlineEntry: function(record) {

        const projectStore = Ext.create('Netresearch.store.Projects', {
            autoLoad: false
        });

        if (!record) {
            record = {};
        }

        const lastRecord = this.getStore().getAt(0); // first();

        // init date
        const date = record.date ? record.date : this.getNewDate();

        // Determine new start and end time
        let start = this.roundTime(this.getNewDate());
        let end   = this.roundTime(this.getNewDate());

        let editStartColumn = 2;

        // suggest start time if possible
        if (settingsData.suggest_time) {
            // either by last entry
            if ((lastRecord != undefined)
                && (lastRecord.data)
                && (this.isSameDay(lastRecord.data.date, date))) {
                    start = new Date(lastRecord.data.end);
            }
            // or by widget start time
            else if (this.isSameDay(this.startTime, date)) {
                start = new Date(this.startTime);
            }

            if (this.getMinutesFromMidnight(start) > this.getMinutesFromMidnight(end))
                end = new Date(start);
            editStartColumn = 4;
        }

        /* Go to activity column if customer and project are already set */
        if (record.customer && record.project) {
            editStartColumn = 7;
            if (record.activity)
                editStartColumn++;
        }

        /* Set record data */
        const newRecord = Ext.ModelManager.create({
            id:          '',
            date:        Ext.Date.clearTime(date),
            start:       settingsData.suggest_time ? start : null,
            end:         settingsData.suggest_time ? end   : null,
            customer:    record.customer           ? record.customer       : null,
            project:     record.project            ? record.project        : null,
            activity:    record.activity           ? record.activity       : null,
            description: record.description        ? record.description    : null,
            ticket:      record.ticket             ? record.ticket         : null,
            extTicket:   record.extTicket          ? record.extTicket      : null,
            extTicketUrl: record.extTicketUrl      ? record.extTicketUrl   : null,
        }, 'Netresearch.model.Entry');

        newRecord.dirty = true;
        this.getStore().insert(0, newRecord);

        /* Set different start position */
        this.editingPlugin.startEditByPosition({row: 0, column: editStartColumn});
    },

    getCustomerName: function(id) {
        const store = this.customerStore;
        for (let c = 0; c < store.getCount(); c++) {
            const record = store.getAt(c);
            if (record.data.id == id)
                return record.data.name;
        }
        return null;
    },

    getProjectName: function(id) {
        const store = this.projectStore;
        for (let c = 0; c < store.getCount(); c++) {
            const record = store.getAt(c);
            if (record.data.id == id)
                return record.data.name;
        }
        return null;
    },

    getActivityName: function(id) {
        const activity = this.activityStore.findRecord('id', id);
        if (undefined != activity)
            return activity.data.name;
        return null;
    },

    isEditing: function() {
        return (undefined != this.editingPlugin)
            && (undefined != this.editingPlugin.activeRecord);
    },

    /* Show shortcuts help window */
    displayShortcuts: function() {
        if (this.isEditing())
            return;

        const shortcuts = ['ALT-A: Eintrag hinzufügen (<b>A</b>dd)',
                'ALT-C: Selektierten/Letzten Eintrag fortsetzen (<b>C</b>ontinue)',
                'ALT-E: Selektierten/Letzten Eintrag editieren (<b>E</b>dit)',
                'ALT-I: Info zu Selektiertem/Letztem Eintrag anzeigen (<b>I</b>nfo)',
                'ALT-P: Letzten Eintrag verlängern (<b>P</b>rolong)',
                'ALT-R: Anzeige aktualisieren (<b>R</b>eload)',
                'ALT-X: Liste exportieren (E<b>x</b>port)',
                '',
                '?: Hilfefenster anzeigen'];
        const grid = this;
        Ext.MessageBox.alert('Shortcuts', shortcuts.join('<br/>'), function(btn) {
            grid.getView().el.focus();
        });
    },

    selectRow: function(index) {
        this.getFocus();

        if (1 > this.store.getCount())
            return;

        // limit Index
        if (index < 0)
            index = 0;
        else if (index >= this.store.getCount())
            index = this.store.getCount() - 1;
        this.getView().select(index);
    },

    getFocus: function() {
        if (undefined != this.getView().el)
            this.getView().el.focus();
    }

});

if ((undefined != settingsData) && (settingsData['locale'] == 'de')) {
    Ext.apply(Netresearch.widget.Tracking.prototype, {
        _tabTitle: 'Zeiterfassung',
        _dateTitle: 'Datum',
        _startTitle: 'Start',
        _endTitle: 'Ende',
        _ticketTitle: 'Fall',
        _customerTitle: 'Kunde',
        _projectTitle: 'Projekt',
        _activityTitle: 'Tätigkeit',
        _descriptionTitle: 'Beschreibung',
        _durationTitle: 'Dauer',
        _infoTitle: 'Info',
        _continueTitle: 'Fortsetzen',
        _deleteTitle: 'Löschen',
        _addTitle : 'Neuer Eintrag',
        _refreshTitle: 'Aktualisieren',
        _exportTitle: 'Exportieren',
        _daysTitle: 'Tage',
        _ongoingSaveTitle: 'Ein vorheriger Speichervorgang ist noch nicht abgeschlossen.',
        _attentionTitle: 'Achtung',
        _confirmDeleteTitle: 'Wirklich löschen?',
        _overviewTitle: 'Übersicht',
        _entriesTitle: 'Einträge',
        _totalDurationTitle: 'Gesamtzeit',
        _ownDurationTitle: 'Meine Zeit',
        _plannedTitle: 'Geplant',
        _statusTitle: 'Stand',
        _minDurationTitle: 'Eine Tätigkeit muss mind. 1 Minute gedauert haben.',
        _customerProjectMismatchTitle: 'Projekt und Kunde passen nicht zusammen',
        _chooseOtherCustomerOrProjectTitle: 'Bitte wähle ein anderes Projekt oder Kunden.',
        _noActivityGivenTitle:  'Keine Tätigkeit angegeben',
        _noTicketGivenTitle:  'Kein Ticket angegeben',
        _informationRetrievalErrorTitle: 'Fehler beim Abrufen der Informationen',
        _sessionExpiredTitle: 'Die Session ist abgelaufen - bitte neu anmelden.',
        _saveErrorTitle: 'Fehler beim Speichern',
        _possibleErrorsTitle: 'Mögliche Fehler',
        _loginAgainTitle: 'Bitte melde dich am TimeTracker neu an (Logout + Login).',
        _timesOverlapTitle: 'Zeiten überlappen sich.',
        _checkTimesTitle: 'Bitte prüfe Start- und Endzeit deines Eintrags.',
        _fieldsMissingTitle: 'Es fehlen Felder.',
        _checkFieldsTitle: 'Bitte prüfe Kunde, Projekt und Tätigkeit.',
        _errorTitle: 'Fehler',
        _successTitle: 'Erfolg'
    });
}

/**
 * @TODO: to be completed
 *
if ((undefined != settingsData) && (settingsData['locale'] == 'ru')) {
    Ext.apply(Netresearch.widget.Tracking.prototype, {
        _tabTitle:  'Время слежения',
        _dateTitle: 'день',
        _userTitle: 'сотрудник',
        _startTitle: 'начало',
        _endTitle: 'конец',
        _ticketTitle: 'билет',
        _customerTitle: 'клиент',
        _projectTitle: 'проект',
        _activityTitle: 'деятельность',
        _descriptionTitle: 'описание',
        _durationTitle: 'срок',
        _infoTitle: 'инфо',
        _continueTitle: 'продолжать',
        _deleteTitle: 'удалять',
        _addTitle : 'добавлять',
        _refreshTitle: 'перезагружать',
        _exportTitle: 'экспорт',
        _daysTitle: 'дней',
        _ongoingSaveTitle: 'Ein vorheriger Speichervorgang ist noch nicht abgeschlossen.',
        _attentionTitle: 'Achtung',
        _confirmDeleteTitle: 'Wirklich löschen?',
        _overviewTitle: 'Übersicht',
        _entriesTitle: 'Einträge',
        _totalDurationTitle: 'Gesamtzeit',
        _ownDurationTitle: 'Meine Zeit',
        _plannedTitle: 'Geplant',
        _statusTitle: 'Stand',
        _minDurationTitle: 'Eine Tätigkeit muss mind. 1 Minute gedauert haben.',
        _customerProjectMismatchTitle: 'Projekt und Kunde passen nicht zusammen',
        _chooseOtherCustomerOrProjectTitle: 'Bitte wähle ein anderes Projekt oder Kunden.',
        _noActivityGivenTitle:  'Keine Tätigkeit angegeben',
        _noTicketGivenTitle:  'Kein Ticket angegeben',
        _informationRetrievalErrorTitle: 'Fehler beim Abrufen der Informationen',
        _sessionExpiredTitle: 'Die Session ist abgelaufen - bitte neu anmelden.',
        _saveErrorTitle: 'Fehler beim Speichern',
        _possibleErrorsTitle: 'Mögliche Fehler',
        _loginAgainTitle: 'Bitte melde dich am TimeTracker neu an (Logout + Login).',
        _timesOverlapTitle: 'Zeiten überlappen sich.',
        _checkTimesTitle: 'Bitte prüfe Start- und Endzeit deines Eintrags.',
        _fieldsMissingTitle: 'Es fehlen Felder.',
        _checkFieldsTitle: 'Bitte prüfe Kunde, Projekt und Tätigkeit.',
        _errorTitle: 'Fehler',
        _successTitle: 'Erfolg'
    });
}
*/
