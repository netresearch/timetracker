Ext.define('Netresearch.store.Entries', {
    extend: 'Ext.data.Store',
    requires: [
        'Netresearch.model.Entry'
    ],
//    autoDestroy: true,
    autoLoad: false,
    sortOnLoad: true,
    model: 'Netresearch.model.Entry',
    proxy: {
        type: 'ajax',
        url: url + 'getData/days/3',
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
            property: 'end',
            direction:'DESC'
    }, {
            property: 'id',
            direction:'DESC'
    }]
});

