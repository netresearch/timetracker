Ext.define('Netresearch.store.TicketSystems', {
    extend: 'Ext.data.Store',
    requires: [
        'Netresearch.model.TicketSystem'
    ],
    autoDestroy: false,
    autoLoad: true,
    model: 'Netresearch.model.TicketSystem',
    proxy: {
        type: 'ajax',
        url: url + 'getTicketSystems',
        reader: {
            type: 'json',
            record: 'ticketSystem'
        }
    }
});