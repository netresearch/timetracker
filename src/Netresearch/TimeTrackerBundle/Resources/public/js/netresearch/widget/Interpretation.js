Ext.define('Netresearch.widget.Interpretation', {

    extend: 'Ext.panel.Panel',

    requires: [
        'Netresearch.widget.Tracking',
        'Netresearch.store.AdminCustomers',
        'Netresearch.store.AdminProjects',
        'Netresearch.store.Activities',
        'Netresearch.store.AdminTeams',
        'Netresearch.store.Users'
    ],

    customerStore: Ext.create('Netresearch.store.AdminCustomers'),
    projectStore: Ext.create('Netresearch.store.AdminProjects'),
    filterableProjectStore: Ext.create('Netresearch.store.AdminProjects'),
    teamStore: Ext.create('Netresearch.store.AdminTeams'),
    userStore: Ext.create('Netresearch.store.Users'),
    activityFilterStore: Ext.create('Netresearch.store.Activities'),

    /* Strings */
    _tabTitle: 'Interpretation',
    _monthTitle: 'Month',
    _yearTitle: 'Year',
    _customerTitle: 'Customer',
    _projectTitle: 'Project',
    _hoursTitle: 'Hours',
    _ticketTitle: 'Ticket',
    _userTitle: 'User',
    _teamTitle: 'Team',
    _activityTitle: 'Activity',
    _descriptionTitle: 'Description',
    _dayTitle: 'Day',
    _searchInDescriptionTitle: 'Search in description',
    _effortByCustomerTitle: 'Effort by customer',
    _effortByProjectTitle: 'Effort by project',
    _effortByTicketTitle: 'Effort by ticket',
    _effortByUserTitle: 'Effort by user',
    _effortByTeamTitle: 'Effort by team',
    _effortByDayTitle: 'Effort by day',
    _effortByActivityTitle: 'Effort by activity',
    _refreshTitle: 'Refresh',
    _resetTitle: 'Reset',
    _shortcutsTitle: 'Shortcuts',
    _showHelpTitle: 'Show help',
    _noDataFoundTitle: 'No data found',
    _lastEntriesTitle: 'Last entries',
    _attentionTitle: 'Attention',
    _monthWithoutYearTitle: 'A month without a year does not make sense.',
    _chooseCustomerProjectUserOrYearAndMonthTitle: 'Please choose at least customer, project, user or year and month.',

    date: new Date(),
    curMonth: new Date().getMonth() + 1,
    curYear: new Date().getFullYear(),

    /* Create tmp stores */
    spentProjectsStore: Ext.create('Ext.data.JsonStore', {
        fields: ['name', 'hours', 'quota'],
        autoLoad: false,
        proxy: {
            type: 'ajax',
            url: url + 'interpretation/project',
            reader: {
                type: 'json'
            }
        }
    }),

    spentCustomersStore: Ext.create('Ext.data.JsonStore', {
        fields: ['name', 'hours', 'quota'],
        autoLoad: false,
        proxy: {
            type: 'ajax',
            url: url + 'interpretation/customer',
            reader: {
                type: 'json'
            }
        }
    }),

    activityStore: Ext.create('Ext.data.JsonStore', {
        fields: ['name', 'hours', 'quota'],
        autoLoad: false,
        proxy: {
            type: 'ajax',
            url: url + 'interpretation/activity',
            reader: {
                type: 'json'
            }
        }
    }),

    timeStore: Ext.create('Ext.data.JsonStore', {
        fields: ['day', 'hours', 'quota'],
        autoLoad: false,
        proxy: {
            type: 'ajax',
            url: url + 'interpretation/time',
            reader: {
                type: 'json'
            }
        }
    }),

    entryStore: Ext.create('Ext.data.JsonStore', {
        fields: ['name', 'hours', 'quota'],
        requires: [
            'Netresearch.model.Entry'
        ],
        autoLoad: false,
        sortOnLoad: true,
        model: 'Netresearch.model.Entry',
        proxy: {
            type: 'ajax',
            url: url + 'interpretation/entries',
            reader: {
                type: 'json',
                record: 'entry'
            }
        },
        sorters: [{
                property: 'date',
                direction:'DESC'
        }, {
                property: 'start',
                direction:'DESC'
        }, {
                property: 'id',
                direction:'DESC'
        }]
    }),

    ticketStore: Ext.create('Ext.data.JsonStore', {
        fields: ['name', 'hours', 'quota'],
        autoLoad: false,
        proxy: {
            type: 'ajax',
            url: url + 'interpretation/ticket',
            reader: {
                type: 'json'
            }
        }
    }),

    developerStore: Ext.create('Ext.data.JsonStore', {
        fields: ['name', 'hours', 'quota'],
        autoLoad: false,
        proxy: {
            type: 'ajax',
            url: url + 'interpretation/user',
            reader: {
                type: 'json'
            }
        }
    }),

    initComponent: function() {
        var chartWidth = Ext.getBody().getWidth() - 40;

        var monthArray = Ext.Array.map(Ext.Date.monthNames, function (e) { return [e]; });
        var months = [];
        for (var c=1; c <= 12; c++) {
            months.push({
                value: c,
                displayname: monthArray[(c-1)]
            });
        }

        var monthStore = new Ext.data.Store({
            fields: ['value', 'displayname'],
            data: months
        });

        /* Calculate last 5 years dynamically */
        var years = [
            { year: this.curYear }];
        for (var y = 1; y <= 4; y++)
            years.push({year: this.curYear - y });
        var yearStore = Ext.create('Ext.data.Store', {
            fields: ['year'],
            data: years
        });

        var customerPanel = Ext.create('Ext.panel.Panel', {
            frame: true,
            title: this._effortByCustomerTitle,
            collapsible: false,
            width: '100%',
            margin: '0 0 10 0',
            stateful: false,
            stateId: 'projectPanel',
            items: [
                Ext.create('Ext.chart.Chart', {
                    width: chartWidth,
                    height: 400,
                    animate: true,
                    store: this.spentCustomersStore,
                    axes: [
                        {
                            type: 'Numeric',
                            position: 'bottom',
                            fields: ['hours'],
                            label: {
                                renderer: Ext.util.Format.numberRenderer('0.00')
                            },
                            title: this._hoursTitle,
                            grid: true,
                            minimum: 0
                        }, {
                            type: 'Category',
                            position: 'left',
                            fields: ['name'],
                            title: this._customerTitle
                        }
                    ],
                    series: [
                        {
                            type: 'bar',
                            axis: 'bottom',
                            highlight: false,
                            tips: {
                                trackMouse: true,
                                width: 300,
                                height: 50,
                                renderer: function(storeItem, item) {
                                    this.setTitle(storeItem.get('name') + ': ' + formatDuration(Math.round(storeItem.get('hours')*60), true) + ' = ' + storeItem.get('quota'));
                                }
                            },
                            label: {
                                display: 'insideStart',
                                renderer: Ext.util.Format.numberRenderer('0.00'),
                                field: 'hours',
                                orientation: 'horizontal'
                            },
                            xField: 'name',
                            yField: ['hours']
                        }
                    ]
                })
            ]
        });

        var projectPanel = Ext.create('Ext.panel.Panel', {
            frame: true,
            title: this._effortByProjectTitle,
            collapsible: false,
            width: '100%',
            margin: '0 0 10 0',
            stateful: false,
            stateId: 'projectPanel',
            items: [
                Ext.create('Ext.chart.Chart', {
                    width: chartWidth,
                    height: 400,
                    animate: true,
                    store: this.spentProjectsStore,
                    axes: [
                        {
                            type: 'Numeric',
                            position: 'bottom',
                            fields: ['hours'],
                            label: {
                                renderer: Ext.util.Format.numberRenderer('0.00')
                            },
                            title: this._hoursTitle,
                            grid: true,
                            minimum: 0
                        }, {
                            type: 'Category',
                            position: 'left',
                            fields: ['name'],
                            title: this._projectTitle
                        }
                    ],
                    series: [
                        {
                            type: 'bar',
                            axis: 'bottom',
                            highlight: false,
                            tips: {
                                trackMouse: true,
                                width: 300,
                                height: 50,
                                renderer: function(storeItem, item) {
                                    this.setTitle(storeItem.get('name') + ': ' + formatDuration(Math.round(storeItem.get('hours')*60), true) + ' = ' + storeItem.get('quota'));
                                }
                            },
                            label: {
                                display: 'insideStart',
                                renderer: Ext.util.Format.numberRenderer('0.00'),
                                field: 'hours',
                                orientation: 'horizontal'
                            },
                            xField: 'name',
                            yField: ['hours']
                        }
                    ]
                })
            ]
        });


        var activityPanel = Ext.create('Ext.panel.Panel', {
            frame: true,
            title: this._effortByActivityTitle,
            collapsible: false,
            width: '100%',
            margin: '0 0 10 0',
            stateful: false,
            stateId: 'activityPanel',
            items: [
                Ext.create('Ext.chart.Chart', {
                    width: chartWidth,
                    height: 400,
                    animate: true,
                    store: this.activityStore,
                    axes: [
                        {
                            type: 'Numeric',
                            position: 'bottom',
                            fields: ['hours'],
                            label: {
                                renderer: Ext.util.Format.numberRenderer('0.00')
                            },
                            title: this._hoursTitle,
                            grid: true,
                            minimum: 0
                        }, {
                            type: 'Category',
                            position: 'left',
                            fields: ['name'],
                            title: this._activityTitle
                        }
                    ],
                    series: [
                        {
                            type: 'bar',
                            axis: 'bottom',
                            highlight: false,
                            tips: {
                                trackMouse: true,
                                width: 300,
                                height: 50,
                                renderer: function(storeItem, item) {
                                    this.setTitle(storeItem.get('name') + ': ' + formatDuration(Math.round(storeItem.get('hours')*60), true) + " = " + storeItem.get("quota"));
                                }
                            },
                            label: {
                                display: 'insideStart',
                                renderer: Ext.util.Format.numberRenderer('0.00'),
                                field: 'hours',
                                orientation: 'horizontal'
                            },
                            xField: 'name',
                            yField: ['hours']
                        }
                    ]
                })
            ]
        });

        var timePanel = Ext.create('Ext.panel.Panel', {
            title: this._effortByDaysTitle,
            frame: true,
            collapsible: false,
            width: '100%',
            margin: '0 0 10 0',
            stateful: false,
            stateId: 'timePanel',
            items: [
                Ext.create('Ext.chart.Chart', {
                    width: chartWidth,
                    height: 400,
                    animate: true,
                    store: this.timeStore,
                    axes: [
                        {
                            title: this._hoursTitle,
                            type: 'Numeric',
                            position: 'left',
                            fields: ['hours'],
                            minimum: 0
                        }, {
                            title: this._dayTitle,
                            type: 'Category',
                            position: 'bottom',
                            fields: ['day']
                        }
                    ],
                    series: [
                        {
                            highlight: true,
                            tips: {
                                trackMouse: true,
                                width: 300,
                                height: 50,
                                renderer: function(storeItem, item) {
                                    this.setTitle(storeItem.get('day') + ': ' + formatDuration(Math.round(storeItem.get('hours')*60), true) + ' = ' + storeItem.get('quota'));
                                }
                            },
                            type: 'line',
                            xField: 'day',
                            yField: 'hours'
                        }
                    ]
                })
            ]
        });

        var ticketPanel = Ext.create('Ext.panel.Panel', {
            frame: true,
            margin: '0 0 10 0',
            title: this._effortByTicketTitle,
            collapsible: false,
            width: '100%',
            stateful: false,
            stateId: 'ticketPanel',
            items: [
                Ext.create('Ext.chart.Chart', {
                    width: chartWidth,
                    height: 400,
                    animate: true,
                    store: this.ticketStore,
                    axes: [
                        {
                            type: 'Numeric',
                            position: 'bottom',
                            fields: ['hours'],
                            label: {
                                renderer: Ext.util.Format.numberRenderer('0.00')
                            },
                            title: this._hoursTitle,
                            grid: true,
                            minimum: 0
                        }, {
                            type: 'Category',
                            position: 'left',
                            fields: ['name'],
                            title: this._ticketTitle
                        }
                    ],
                    series: [
                        {
                            type: 'bar',
                            axis: 'bottom',
                            highlight: false,
                            tips: {
                                trackMouse: true,
                                width: 300,
                                height: 50,
                                renderer: function(storeItem, item) {
                                    this.setTitle(storeItem.get('name') + ': ' + formatDuration(Math.round(storeItem.get('hours')*60), true) + " = " + storeItem.get("quota"));
                                }
                            },
                            label: {
                                display: 'insideStart',
                                renderer: Ext.util.Format.numberRenderer('0.00'),
                                field: 'hours',
                                orientation: 'horizontal'
                            },
                            xField: 'name',
                            yField: ['hours']
                        }
                    ]
                })
            ]
        });

        var developerPanel = Ext.create('Ext.panel.Panel', {
            frame: true,
            margin: '0 0 10 0',
            title: this._effortByUserTitle,
            collapsible: false,
            width: '100%',
            stateful: false,
            stateId: 'developerPanel',
            items: [
                Ext.create('Ext.chart.Chart', {
                    width: chartWidth,
                    height: 400,
                    animate: true,
                    store: this.developerStore,
                    axes: [
                        {
                            type: 'Numeric',
                            position: 'bottom',
                            fields: ['hours'],
                            label: {
                                renderer: Ext.util.Format.numberRenderer('0.00')
                            },
                            title: this._hoursTitle,
                            grid: true,
                            minimum: 0
                        }, {
                            type: 'Category',
                            position: 'left',
                            fields: ['name'],
                            title: this._userTitle
                        }
                    ],
                    series: [
                        {
                            type: 'bar',
                            axis: 'bottom',
                            highlight: false,
                            tips: {
                                trackMouse: true,
                                width: 300,
                                height: 50,
                                renderer: function(storeItem, item) {
                                    this.setTitle(storeItem.get('name') + ': ' + formatDuration(Math.round(storeItem.get('hours')*60), true) + " = " + storeItem.get("quota"));
                                }
                            },
                            label: {
                                display: 'insideStart',
                                renderer: Ext.util.Format.numberRenderer('0.00'),
                                field: 'hours',
                                orientation: 'horizontal'
                            },
                            xField: 'name',
                            yField: ['hours']
                        }
                    ]
                })
            ]
        });

        var entryGrid =
            Ext.create('Netresearch.widget.Tracking', {
                width: chartWidth,
                height: 600,
                store: this.entryStore,
                customerStore: this.customerStore,
                projectStore: this.projectStore,
                title: null,
                tbar: null,
                viewConfig: {
                    getRowClass: null
                },
                listeners: [],
                deleteEntry: function(id) { alert("Nope!"); },
                addInlineEntry: function(record) { alert("Nope!"); }
        });

        var entryPanel = Ext.create('Ext.panel.Panel', {
            frame: true,
            margin: '0 0 10 0',
            title: this._lastEntriesTitle,
            collapsible: false,
            width: '100%',
            stateful: false,
            stateId: 'entryPanel',

            items: [
                entryGrid
            ]
        });

        var widget = this;
        var config = {
            title: this._tabTitle,
            autoScroll: true,
            bodyPadding: 5,
            tbar: [
                Ext.create('Ext.form.field.ComboBox', {
                    id: 'month-interpretation',
                    hideLabel: true,
                    emptyText: this._monthTitle,
                    width: 75,
                    store: monthStore,
                    displayField: 'displayname',
                    valueField: 'value',
                    // mode: 'local',
                    value: this.curMonth
                }),
                Ext.create('Ext.form.field.ComboBox', {
                    id: 'year-interpretation',
                    hideLabel: true,
                    emptyText: this._yearTitle,
                    width: 60,
                    store: yearStore,
                    displayField: 'year',
                    valueField: 'year',
                    mode: 'local',
                    value: this.curYear
                }),
                Ext.create('Ext.form.field.ComboBox', {
                    id: 'customer-interpretation',
                    hideLabel: true,
                    emptyText: this._customerTitle,
                    store: this.customerStore,
                    displayField: 'name',
                    valueField: 'id',
                    mode: 'local',
                    listeners: {
                        select: function(field, value) {
                            widget.filterableProjectStore.load({ 
                                params: {
                                    customer: value[0].data.id
                                }
                            });
                        }
                    }
                }),
                Ext.create('Ext.form.field.ComboBox', {
                    id: 'project-interpretation',
                    hideLabel: true,
                    emptyText: this._projectTitle,
                    store: this.filterableProjectStore,
                    displayField: 'name',
                    valueField: 'id',
                    mode: 'local',
                    listeners: {
                        scope: this,
                        focus: function() {
                            widget.filterableProjectStore.load({
                                params: {
                                    customer: Ext.getCmp('customer-interpretation').getValue()
                                }
                            });
                        }
                    }
                }),
                Ext.create('Ext.form.field.ComboBox', {
                    id: 'team-interpretation',
                    hideLabel: true,
                    emptyText: this._teamTitle,
                    width: 110,
                    store: this.teamStore,
                    displayField: 'name',
                    valueField: 'id',
                    mode: 'local'
                }),
                Ext.create('Ext.form.field.ComboBox', {
                    id: 'user-interpretation',
                    hideLabel: true,
                    emptyText: this._userTitle,
                    store: this.userStore,
                    displayField: 'username',
                    valueField: 'id',
                    mode: 'local',
                    value: settingsData.user_id
                }),
                Ext.create('Ext.form.field.ComboBox', {
                    id: 'activity-interpretation',
                    hideLabel: true,
                    emptyText: this._activityTitle,
                    store: this.activityFilterStore,
                    displayField: 'name',
                    valueField: 'id',
                    mode: 'local'
                }),
                Ext.create('Ext.form.field.Text', {
                    id: 'ticket-interpretation',
                    dataIndex: 'ticket',
                    emptyText: this._ticketTitle,
                    selectOnFocus: true,
                    selectOnTab: true,
                    width: 120
                }),
                Ext.create('Ext.form.field.Text', {
                    id: 'description-interpretation',
                    dataIndex: 'description',
                    emptyText: this._searchInDescriptionTitle,
                    selectOnFocus: true,
                    selectOnTab: true
                }),
                {
                    iconCls: 'icon-refresh',
                    tooltip: this._refreshTitle,
                    handler: function() {
                        widget.refresh();
                    }
                },
                {
                    iconCls: 'icon-delete',
                    tooltip: this._resetTitle,
                    handler: function() {
                        widget.reset();
                    }
                }
            ],
            items: [
                customerPanel,
                projectPanel,
                ticketPanel,
                activityPanel,
                developerPanel,
                timePanel,
                entryPanel
            ]
        };

        Ext.applyIf(this,config);
        this.callParent();
    },

    reset: function() {
        Ext.getCmp('month-interpretation').setValue(this.curMonth);
        Ext.getCmp('year-interpretation').setValue(this.curYear);
        Ext.getCmp('customer-interpretation').setValue(undefined);
        Ext.getCmp('project-interpretation').setValue(undefined);
        Ext.getCmp('team-interpretation').setValue(undefined);
        Ext.getCmp('user-interpretation').setValue(settingsData.user_id);
        Ext.getCmp('activity-interpretation').setValue(undefined);
        Ext.getCmp('ticket-interpretation').setValue(undefined);
        Ext.getCmp('description-interpretation').setValue(undefined);

        this.spentCustomersStore.load();
        this.spentProjectsStore.load();
        this.ticketStore.load();
        this.activityStore.load();
        this.timeStore.load();
        this.developerStore.load();
        this.entryStore.load();
    },

    refresh: function() {
        var month = Ext.getCmp('month-interpretation').getValue();
        var year = Ext.getCmp('year-interpretation').getValue();
        var customer = Ext.getCmp('customer-interpretation').getValue();
        var project = Ext.getCmp('project-interpretation').getValue();
        var team = Ext.getCmp('team-interpretation').getValue();
        var user = Ext.getCmp('user-interpretation').getValue();
        var activity = Ext.getCmp('activity-interpretation').getValue();
        var ticket = Ext.getCmp('ticket-interpretation').getValue();
        var description = Ext.getCmp('description-interpretation').getValue();

        // Same check as server-side
        if ((!customer) && (!project) && (!user) && (!ticket) && (!year || !month) && (!year || !team)) {
            Ext.MessageBox.alert('Fehler', "Es muss mindestens Kunde, Projekt, Ticket, Mitarbeiter oder Jahr und Monat ausgewählt werden.");
            return;
        }

        if ((month) && (!year)) {
            Ext.MessageBox.alert('Fehler', this._monthWithoutYearTitle);
            return;
        }

        this.spentProjectsStore.load({
            params: {
                month: month,
                year: year,
                customer: customer,
                project: project,
                team: team,
                user: user,
                activity: activity,
                ticket: ticket,
                description : description
            }
        });

        this.spentCustomersStore.load({
            params: {
                month: month,
                year: year,
                customer: customer,
                project: project,
                team: team,
                user: user,
                activity: activity,
                ticket: ticket,
                description : description
            }
        });

        this.ticketStore.load({
            params: {
                month: month,
                year: year,
                customer: customer,
                project: project,
                team: team,
                user: user,
                activity: activity,
                ticket: ticket,
                description : description
            }
        });

        this.activityStore.load({
            params: {
                month: month,
                year: year,
                customer: customer,
                project: project,
                team: team,
                user: user,
                activity: activity,
                ticket: ticket,
                description : description
            },
            callback: function(records) {
                // Show alert box if no data were found
                if (typeof records == 'undefined') {
                    Ext.MessageBox.alert(this._attentionTitle, this._noDataFoundTitle);
                }
            }
        });

        this.timeStore.load({
            params: {
                month: month,
                year: year,
                customer: customer,
                project: project,
                team: team,
                user: user,
                activity: activity,
                ticket: ticket,
                description : description
            }
        });

        this.entryStore.load({
            params: {
                month: month,
                year: year,
                customer: customer,
                project: project,
                team: team,
                user: user,
                activity: activity,
                ticket: ticket,
                description : description
            }
        });

        this.developerStore.load({
            params: {
                month: month,
                year: year,
                customer: customer,
                project: project,
                team: team,
                user: user,
                activity: activity,
                ticket: ticket,
                description : description
            }
        });
    },

    displayShortcuts: function() {
        var shortcuts = new Array(
                'ALT-R: ' + this._refreshTitle + ' (<b>R</b>efresh)', 
                '', 
                '?: ' + this._showHelpTitle);
        var grid = this;
        Ext.MessageBox.alert(this._shortcutsTitle, shortcuts.join('<br/>'), function(btn) {
            grid.getView().el.focus();
        });
    }
});


if ((undefined != settingsData) && (settingsData['locale'] == 'de')) {
    Ext.apply(Netresearch.widget.Interpretation.prototype, {
        _tabTitle: 'Auswertung',
        _monthTitle: 'Monat',
        _yearTitle: 'Jahr',
        _customerTitle: 'Kunde',
        _hoursTitle: 'Stunden',
        _projectTitle: 'Projekt',
        _userTitle: 'Mitarbeiter',
        _teamTitle: 'Team',
        _dayTitle: 'Tag',
        _activityTitle: 'Tätigkeit',
        _ticketTitle: 'Fall',
        _descriptionTitle: 'Beschreibung',
        _searchInDescriptionTitle: 'Suche in Beschreibung',
        _effortByCustomerTitle: 'Aufwand nach Kunden',
        _effortByProjectTitle: 'Aufwand nach Projekt',
        _effortByUserTitle: 'Aufwand nach Mitarbeitern',
        _effortByTeamTitle: 'Aufwand nach Teams',
        _effortByActivityTitle: 'Aufwand nach Tätigkeiten',
        _effortByDayTitle: 'Aufwand nach Tagen',
        _effortByTicketTitle: 'Aufwand nach Fall',
        _shortcutsTitle: 'Shortcuts',
        _refreshTitle: 'Aktualisieren',
        _resetTitle: 'Zurücksetzen',
        _showHelpTitle: 'Hilfe anzeigen',
        _noDataFoundTitle: 'Es konnten keine Daten gefunden werden.',
        _lastEntriesTitle: 'Letzte Einträge',
        _attentionTitle: 'Achtung',
        _monthWithoutYearTitle: 'Ein Monat ohne Jahr macht keinen Sinn.',
        _chooseCustomerProjectUserOrYearAndMonthTitle: 'Es muss mindestens Kunde, Projekt, Mitarbeiter oder Jahr und Monat ausgewählt werden.'
    });
}
