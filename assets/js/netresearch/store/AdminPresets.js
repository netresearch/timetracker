Ext.define('Netresearch.store.AdminPresets', {
    extend: 'Ext.data.Store',

    requires: [
        'Netresearch.model.Preset'
    ],

    autoLoad: false,
    sortOnLoad: true,
    model: 'Netresearch.model.Preset',
    proxy: {
        type: 'ajax',
        url: url + 'presets',
        reader: {
            type: 'json',
            record: 'preset'
        }
    }
});

