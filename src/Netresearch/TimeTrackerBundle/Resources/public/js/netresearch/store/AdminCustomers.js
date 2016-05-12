Ext.define('Netresearch.store.AdminCustomers', {
	extend: 'Ext.data.Store',
	
	requires: [
   	    'Netresearch.model.Customer'
   	],
	
    autoLoad: true,
    sortOnLoad: true,
    model: 'Netresearch.model.Customer',
    proxy: {
        type: 'ajax',
        url: url + 'getAllCustomers',
        reader: {
            type: 'json',
            record: 'customer'
        }
    },
    sorters: [{
            property: 'name',
            direction:'ASC'
    }]
});
