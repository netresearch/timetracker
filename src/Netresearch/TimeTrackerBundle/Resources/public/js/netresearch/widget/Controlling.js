/*
 * Controlling tab
 *
 * To have the ability to export monthly reports
 */
Ext.define('Netresearch.widget.Controlling', {
    extend: 'Ext.tab.Panel',

	requires: [
   	    'Netresearch.store.AdminUsers'
    ],

	userStore: Ext.create('Netresearch.store.AdminUsers'),

    curYear: new Date().getFullYear(),

    /* Strings */
    _monthlyStatement: 'Monthly statement',
    _userTitle: 'User',
    _yearTitle: 'Year',
    _monthTitle: 'Month',
    _exportTitle: 'Export',
    _tabTitle: 'Controlling',
    

    initComponent: function() {
        
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
                    var year = parseInt(Ext.getCmp("cnt-year").value);
                    var month = parseInt(Ext.getCmp("cnt-month").value);
                    this.exportEntries(user, year, month);
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

    exportEntries: function(user, year, month) {
        if ((undefined == user) || (null == user) || ('' == user) || (1 > user))
            user = 0;
        window.location.href = 'controlling/export/' + user + '/' + year + '/' + month;
    }

});

if ((undefined != settingsData) && (settingsData['locale'] == 'de')) {
    Ext.apply(Netresearch.widget.Controlling.prototype, {
        _monthlyStatement: 'Monats-Abrechnung',
        _userTitle: 'Mitarbeiter',
        _yearTitle: 'Jahr',
        _monthTitle: 'Monat',
        _exportTitle: 'Exportieren',
        _tabTitle: 'Abrechnung'
    });
}
