Ext.define('Netresearch.store.TicketSystems', {
    extend: 'Ext.data.Store',
    requires: [
        'Netresearch.model.TicketSystem'
    ],
    autoDestroy: false,
    autoLoad: false,
    model: 'Netresearch.model.TicketSystem',
    proxy: {
        type: 'ajax',
        url: url + 'ticketsystems',
        reader: {
            type: 'json',
            record: 'ticketSystem'
        }
    }
});
