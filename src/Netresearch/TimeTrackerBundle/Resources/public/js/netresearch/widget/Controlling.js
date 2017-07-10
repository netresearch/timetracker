/*
 * Controlling tab
 *
 * To have the ability to export monthly reports
 */
Ext.define('Netresearch.widget.Controlling', {
    extend: 'Ext.tab.Panel',

	requires: [
            'Netresearch.store.AdminUsers',
            'Netresearch.store.AdminProjects',
            'Netresearch.store.AdminCustomers',
    ],

	userStore: Ext.create('Netresearch.store.AdminUsers'),
	projectStore: Ext.create('Netresearch.store.AdminProjects'),
	customerStore: Ext.create('Netresearch.store.AdminCustomers'),

    curYear: new Date().getFullYear(),

    /* Strings */
    _monthlyStatement: 'Monthly statement',
    _userTitle: 'User',
    _projectTitle: 'Project',
    _customerTitle: 'Customer',
    _yearTitle: 'Year',
    _monthTitle: 'Month',
    _exportTitle: 'Export',
    _tabTitle: 'Controlling',


    initComponent: function() {
        this.on('render', this.refreshStores, this);

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

        // Calculate last 5 years dynamically
        var years = [
            { year: this.curYear }];
        for (var y = 1; y <= 4; y++)
            years.push({year: this.curYear - y });
        var yearStore = Ext.create('Ext.data.Store', {
            fields: ['year'],
            data: years
        });
    
        var date = new Date();
        var curMonth = date.getMonth() + 1;
        if (curMonth > 1)
            curMonth--;

        var form = new Ext.form.FormPanel({
            url: url + 'controlling/export',
            title: this._monthlyStatement,
            bodyPadding: '20',
            width: 360,
            height: 200,
            items: [{
                id: 'cnt-user',
                xtype: 'combo',
                store: this.userStore,
                mode: 'local',
                fieldLabel: this._userTitle,
                name: 'user',
                labelWidth: 100,
                width: 260,
                valueField: 'id',
                displayField: 'username',
                anchor: '100%',
                value: ''
            }, {
                id: 'cnt-project',
                xtype: 'combo',
                store: this.projectStore,
                mode: 'local',
                fieldLabel: this._projectTitle,
                name: 'project',
                labelWidth: 100,
                width: 260,
                valueField: 'id',
                displayField: 'name',
                anchor: '100%',
                value: ''
            }, {
                id: 'cnt-customer',
                xtype: 'combo',
                store: this.customerStore,
                mode: 'local',
                fieldLabel: this._customerTitle,
                name: 'customer',
                labelWidth: 100,
                width: 260,
                valueField: 'id',
                displayField: 'name',
                anchor: '100%',
                value: ''
            }, {
                id: 'cnt-year',
                xtype: 'combo',
                store: yearStore,
                mode: 'local',
                fieldLabel: this._yearTitle,
                name: 'year',
                labelWidth: 100,
                width: 260,
                displayField: 'year',
                valueField: 'year',
                value: this.curYear
            }, {
                id: 'cnt-month',
                xtype: 'combo',
                store: monthStore,
                mode: 'local',
                fieldLabel: this._monthTitle,
                name: 'month',
                labelWidth: 100,
                width: 260,
                displayField: 'displayname',
                valueField: 'value',
                value: curMonth
            }],
            buttons: [{
                text: this._exportTitle,
                scope: this,
                handler: function() {
                    var user = Ext.getCmp("cnt-user").value;
                    var year = parseInt(Ext.getCmp("cnt-year").value) || 0;
                    var month = parseInt(Ext.getCmp("cnt-month").value) || 0;
                    var project = Ext.getCmp("cnt-project").value;
                    var customer = Ext.getCmp("cnt-customer").value;
                    this.exportEntries(user, year, month, project, customer);
                }
            }]
        });

        /* Define container panel */
        var controllingPanel = Ext.create('Ext.panel.Panel', {
            layout: 'fit',
            frame: true,
            title: this._monthlyStatement,
            collapsible: false,
            width: '100%',
            margin: '0 0 10 0',
            items: [ form ]
        });
        var config = {
            title: this._tabTitle,
            items: [ controllingPanel ]
        };

        /* Apply settings */
        Ext.applyIf(this, config);
        this.callParent();
    },

    exportEntries: function(user, year, month, project, customer) {
        if ((undefined == user) || (null == user) || ('' == user) || (1 > user)) {
            user = 0;
        }
        if ((undefined == project) || (null == project) || ('' == project) || (1 > project)) {
            project = 0;
        }
        if ((undefined == customer) || (null == customer) || ('' == customer) || (1 > customer)) {
            customer = 0;
        }

        window.location.href = 'controlling/export/'
            + user + '/'
            + year + '/'
            + month + '/'
            + project + '/'
            + customer
    },

    refreshStores: function () {
        this.userStore.load();
        this.projectStore.load();
        this.customerStore.load();
    }

});

if ((undefined != settingsData) && (settingsData['locale'] == 'de')) {
    Ext.apply(Netresearch.widget.Controlling.prototype, {
        _monthlyStatement: 'Monats-Abrechnung',
        _userTitle: 'Mitarbeiter',
        _projectTitle: 'Projekt',
        _customerTitle: 'Kunde',
        _yearTitle: 'Jahr',
        _monthTitle: 'Monat',
        _exportTitle: 'Exportieren',
        _tabTitle: 'Abrechnung'
    });
}
