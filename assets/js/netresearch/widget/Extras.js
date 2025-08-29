/*
 * Extras tab
 *
 * Simplifies tracking by various (planned) features
 */
Ext.define('Netresearch.widget.Extras', {
    extend: 'Ext.tab.Panel',

	requires: [
   	    'Netresearch.store.AdminPresets'
    ],

    presetStore: Ext.create('Netresearch.store.AdminPresets'),

    /* Strings */
    _tabTitle: 'Extras',
    _bulkEntryTitle: 'Bulk Entry',
    _presetTitle: 'Preset',
    _startDateTitle: 'Start date',
    _endDateTitle: 'End date',
    _startTimeTitle: 'from',
    _startTimeTitleHelp: 'only if contract periods are not used',
    _endTimeTitle: 'to',
    _endTimeTitleHelp: 'only if contract periods are not used',
    _skipWeekendTitle: 'Skip weekends',
    _skipHolidaysTitle: 'Skip holidays',
    _useContractTitle: 'Use time from contract',
    _choosePresetTitle: 'Please choose a preset.',
    _missingDatesTitlei: 'Please specify a start and end date.',
    _missingTimesTitle: 'Please specify a start and end time.',
    _invalidDatesTitlei: 'Please specify a valid date.',
    _overlappingDatesTitle: 'The end date must be later then the start date.',
    _yesTitle: 'Yes',
    _noTitle: 'No',
    _nrHolidaysTitle: 'NR: Holidays',
    _nrSickTitle: 'NR: Sick',
    _nrParentTimeTitle: 'NR: Parent Time',
    _nafHolidaysTitle: 'NAF: Holidays',
    _nafSickTitle: 'NAF: Sick',
    _nafParentTimeTitle: 'NAF: Parent Time',
    _errorTitle: 'Error',
    _successTitle: 'Success',

    initComponent: function() {
        this.on('render', this.refreshStores, this);

        /* Little store for yes/no dropdown */
        var yesnoSourceModel = new Ext.data.ArrayStore({
            fields: ['value', 'displayname'],
            data: [[1, this._yesTitle], [0, this._noTitle]]
        });

        /*
        new Ext.data.ArrayStore({
            fields: ['value', 'displayname'],
            data: [[1, this._nrHolidaysTitle],  [2, this._nrSickTitle],  [3, this._nrParentTimeTitle],
                   [4, this._nafHolidaysTitle], [5, this._nafSickTitle], [6, this._nafParentTimeTitle]]
        });
        */

        var form = new Ext.form.FormPanel({
            url: url + 'tracking/bulkentry',
            frame: true,
            title: this._bulkEntryTitle,
            bodyPadding: '20',
            width: 360,
            height: 250,
            items: [{
                id: 'cnt-preset',
                xtype: 'combo',
                store: this.presetStore,
                mode: 'local',
                fieldLabel: this._presetTitle,
                name: 'preset',
                labelWidth: 100,
                width: 260,
                displayField: 'name',
                valueField: 'id'
            }, {
                id: 'cnt-startdate',
                xtype: 'datefield',
                fieldLabel: this._startDateTitle,
                name: 'startDate',
                format: 'd.m.Y',
                editable: true,
                submitFormat: 'd.m.Y',
                startDay: 1,
                labelWidth: 100,
                width: 260
            }, {
                id: 'cnt-enddate',
                xtype: 'datefield',
                fieldLabel: this._endDateTitle,
                name: 'endDate',
                format: 'd.m.Y',
                editable: true,
                submitFormat: 'd.m.Y',
                startDay: 1,
                labelWidth: 100,
                width: 260
            },{
                id: 'cnt-usecontract',
                xtype: 'combo',
                store: yesnoSourceModel,
                mode: 'local',
                fieldLabel: this._useContractTitle,
                name: 'useContract',
                labelWidth: 100,
                width: 260,
                displayField: 'displayname',
                valueField: 'value',
                value: 1
            }, {
                id: 'cnt-starttime',
                xtype: 'timefield',
                fieldLabel: this._startTimeTitle,
                afterSubTpl: this._startTimeTitleHelp,
                name: 'startTime',
                format: 'H:i',
                increment: 5,
                labelWidth: 100,
                width: 260,
                value: '08:00'
            }, {
                id: 'cnt-endtime',
                xtype: 'timefield',
                fieldLabel: this._endTimeTitle,
                afterSubTpl: this._endTimeTitleHelp,
                name: 'endTime',
                format: 'H:i',
                increment: 5,
                labelWidth: 100,
                width: 260,
                value: '16:00'
            }, {
                id: 'cnt-skipweekend',
                xtype: 'combo',
                store: yesnoSourceModel,
                mode: 'local',
                fieldLabel: this._skipWeekendTitle,
                name: 'skipWeekend',
                labelWidth: 100,
                width: 260,
                displayField: 'displayname',
                valueField: 'value',
                value: 1,
            }, {
                id: 'cnt-skipholidays',
                xtype: 'combo',
                store: yesnoSourceModel,
                mode: 'local',
                fieldLabel: this._skipHolidaysTitle,
                name: 'skipHolidays',
                labelWidth: 100,
                width: 260,
                displayField: 'displayname',
                valueField: 'value',
                value: 1
            }],
            buttons: [{
                text: 'Eintragen',
                scope: this,
                handler: function() {
                    var date = new Date();
                    date.setMilliseconds(0);
                    date.setSeconds(0);
                    date.setMinutes(0);
                    date.setHours(0);

                    var preset = Ext.getCmp("cnt-preset").value;
                    var startdate = Ext.getCmp("cnt-startdate").value;
                    var enddate = Ext.getCmp("cnt-enddate").value;
                    var starttime = Ext.getCmp("cnt-starttime").value;
                    var endtime = Ext.getCmp("cnt-endtime").value;
                    var skipweekend = Ext.getCmp("cnt-skipweekend").value;
                    var skipholidays = Ext.getCmp("cnt-skipholidays").value;
                    var usecontract = Ext.getCmp("cnt-usecontract").value;

                    if ((undefined == preset) || ('' == preset)) {
                        alert(this._choosePresetTitle);
                        return;
                    }
                    if ((undefined == startdate) || (undefined == enddate)
                        || ('' == startdate) || ('' == enddate)) {
                        alert(this._missingDatesTitle);
                        return;
                    }

                    if ((typeof (startdate) != 'object')
                        || (typeof (enddate) != 'object')) {
                        alert(this._invalidDatesTitle);
                        return;
                    }
                    if (startdate.getTime() > enddate.getTime()) {
                        alert("Das Enddatum muss größer/gleich dem Startdatum sein: " + startdate + " bis " + enddate);
                        return;
                    }

                    if (!usecontract) {

                        if ((undefined == starttime) || (undefined == endtime)
                            || ('' == starttime) || ('' == endtime)) {
                            alert(this._missingTimesTitle);
                            return;
                        }



                        if (starttime.getTime() >= endtime.getTime()) {
                            alert("Die Endzeit muss größer der Startzeit sein: " + starttime + " bis " + endtime);
                            return;
                        }
                    }

                    var data = {
                        startdate: startdate,
                        enddate: enddate,
                        starttime: starttime,
                        endtime: endtime,
                        skipweekend: skipweekend,
                        skipholidays: skipholidays,
                        preset: preset,
                        usecontract: usecontract
                    };

                    var panel = this;
                    Ext.Ajax.request({
                        url: url + 'tracking/bulkentry',
                        params: data,
                        success: function(response) {
                            showNotification(panel._successTitle, response.responseText, true);
                        },
                        failure: function(response) {
                            var message = '';
                            try {
                                if (response.status === 422) {
                                    var ct = response.getResponseHeader ? response.getResponseHeader('Content-Type') : '';
                                    if (ct && ct.indexOf('json') !== -1) {
                                        var data = Ext.decode(response.responseText);
                                        if (data && data.violations && Ext.isArray(data.violations) && data.violations.length) {
                                            message = Ext.Array.map(data.violations, function(v){ return v.title || v.message || v; }).join('<br>');
                                        } else if (data && data.message) {
                                            message = data.message;
                                        }
                                    }
                                }
                            } catch (e) {}
                            if (!message) {
                                message = response.responseText;
                            }
                            showNotification(panel._errorTitle, message, false);
                        }
                    });
                }
            }]
        });

        /* Define container panel */
        var extrasPanel = Ext.create('Ext.panel.Panel', {
            layout: 'fit',
            frame: true,
            title: this._bulkEntryTitle,
            collapsible: false,
            width: '100%',
            margin: '0 0 10 0',
            items: [ form ]
        });
        var config = {
            title: this._tabTitle,
            items: [ extrasPanel ]
        };

        /* Apply settings */
        Ext.applyIf(this, config);
        this.callParent();
    },

    refreshStores: function() {
        this.presetStore.load();
    }
});

if ((undefined != settingsData) && (settingsData['locale'] == 'de')) {
    Ext.apply(Netresearch.widget.Extras.prototype, {
        _tabTitle: 'Extras',
        _bulkEntryTitle: 'Massen-Eintragung',
        _presetTitle: 'Vorlage',
        _startDateTitle: 'Startdatum',
        _endDateTitle: 'Enddatum',
        _startTimeTitle: 'von',
        _startTimeTitleHelp: 'nur wenn Vertragszeiten  nicht verwendet werden',
        _endTimeTitle: 'bis',
        _endTimeTitleHelp: 'nur wenn Vertragszeiten  nicht verwendet werden ',
        _skipWeekendTitle: 'Wochenende auslassen',
        _skipHolidaysTitle: 'Feiertage auslassen',
        _useContractTitle: 'Vertragszeiten verwenden',
        _choosePresetTitle: 'Bitte wähle eine Vorlage.',
        _missingDatesTitlei: 'Startdatum und Enddatum müssen angeben werden.',
        _missingTimesTitle: 'Startzeit und Endzeit müssen angegeben werden.',
        _invalidDatesTitlei: 'Das Datum ist inkorrekt angegeben.',
        _overlappingDatesTitle: 'Das Enddatum muss größer als das Startdatum sein.',
        _yesTitle: 'Ja',
        _noTitle: 'Nein',
        _nrHolidaysTitle: 'NR: Urlaub',
        _nrSickTitle: 'NR: Krank',
        _nrParentTimeTitle: 'NR: Elternzeit',
        _nafHolidaysTitle: 'NAF: Urlaub',
        _nafSickTitle: 'NAF: Krank',
        _nafParentTimeTitle: 'NAF: Elternzeit',
        _errorTitle: 'Fehler',
        _successTitle: 'Erfolg'
    });
}

