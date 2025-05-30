Ext.define('Netresearch.store.AdminContracts', {
    extend: 'Ext.data.Store',

    requires: [
        'Netresearch.model.Contract'
    ],

    autoLoad: false,
    model: 'Netresearch.model.Contract',
    proxy: {
        type: 'ajax',
        url: url + 'contracts',
        reader: {
            type: 'json',
            record: 'contract'
        }
    }
});
