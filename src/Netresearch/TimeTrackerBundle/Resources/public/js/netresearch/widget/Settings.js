/*
 * Settings tab
 *
 * User has the ability to change several settings which change the behaviour of the timetracker, hopefully
 */
Ext.define('Netresearch.widget.Settings', {
    extend: 'Ext.tab.Panel',

    requires: [
           'Netresearch.store.AdminUsers'
    ],

    userStore: Ext.create('Netresearch.store.AdminUsers'),

    /* Strings */
    _yesTitle: 'Yes',
    _noTitle: 'No',
    _gridBehaviourTitle: 'Grid behaviour',
    _languageTitle: 'Language',
    _showEmptyLineTitle: 'Show empty line',
    _suggestTimeTitle: 'Suggest time',
    _showFutureTitle: 'Show future',
    _saveTitle: 'Save',
    _generalSettingsTitle: 'General settings',
    _tabTitle: 'Settings',
    _errorTitle: 'Error',
    _successTitle: 'Success',

    initComponent: function() {
        /* Little store for yes/no dropdown */
        var yesnoSourceModel = new Ext.data.ArrayStore({
            fields: ['value', 'displayname'],
            data: [[1, this._yesTitle], [0, this._noTitle]]
        });

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

        var widget = this;
        var form = new Ext.form.FormPanel({
            url: url + 'settings/save',
            frame: true,
            title: this._gridBehaviourTitle,
            bodyPadding: '20',
            width: 300,
            height: 150,
            items: [{
                xtype: 'combo',
                store: localesStore,
                mode: 'local',
                fieldLabel: this._languageTitle,
                name: 'locale',
                labelWidth: 180,
                width: 260,
                displayField: 'displayname',
                valueField: 'value',
                value: settingsData.locale ? settingsData.locale : 'de'
            }, {
                xtype: 'combo',
                store: yesnoSourceModel,
                mode: 'local',
                fieldLabel: this._showEmptyLineTitle,
                name: 'show_empty_line',
                labelWidth: 180,
                width: 260,
                displayField: 'displayname',
                valueField: 'value',
                value: settingsData.show_empty_line ? settingsData.show_empty_line : 0
            }, {
                xtype: 'combo',
                store: yesnoSourceModel,
                mode: 'local',
                fieldLabel: this._suggestTimeTitle,
                name: 'suggest_time',
                labelWidth: 180,
                width: 260,
                displayField: 'displayname',
                valueField: 'value',
                value: settingsData.suggest_time ? settingsData.suggest_time : 0
            }, {
                xtype: 'combo',
                store: yesnoSourceModel,
                mode: 'local',
                fieldLabel: this._showFutureTitle,
                name: 'show_future',
                labelWidth: 180,
                width: 260,
                displayField: 'displayname',
                valueField: 'value',
                value: settingsData.show_future ? settingsData.show_future : 0
            }],
            buttons: [{
                text: this._saveTitle,
                handler: function() {
                    form.getForm().submit({
                        success: function(form, action) {
                            if (settingsData.locale != action.result.locale) {
                                window.location.reload();
                            } else {
                                settingsData = action.result.settings;
                                ttt_items[0].refresh();
                                showNotification(widget._successTitle, action.result.message, true);
                            }
                        },
                        failure: function(form, action) {
                            showNotification(widget._errorTitle, action.result.message, false);
                        }
                    });
                }
            }]
        });

        /* Define container panel */
        var settingsPanel = Ext.create('Ext.panel.Panel', {
            layout: 'fit',
            frame: true,
            title: this._generalSettingsTitle,
            collapsible: false,
            width: '100%',
            margin: '0 0 10 0',
            items: [ form ]
        });
        var config = {
            title: this._tabTitle,
            items: [ settingsPanel ]
        };

        /* Apply settings */
        Ext.applyIf(this, config);
        this.callParent();
    }
});

if ((undefined != settingsData) && (settingsData['locale'] == 'de')) {
    Ext.apply(Netresearch.widget.Settings.prototype, {
        _yesTitle: 'Ja',
        _noTitle: 'Nein',
        _gridBehaviourTitle: 'Grid-Verhalten',
        _languageTitle: 'Sprache',
        _showEmptyLineTitle: 'Immer leere Zeile anzeigen',
        _suggestTimeTitle: 'Zeit vorschlagen',
        _showFutureTitle: 'Zukunft anzeigen',
        _saveTitle: 'Speichern',
        _generalSettingsTitle: 'Allgemeine Einstellungen',
        _tabTitle: 'Einstellungen',
        _errorTitle: 'Fehler',
        _successTitle: 'Erfolg'
    });
}
