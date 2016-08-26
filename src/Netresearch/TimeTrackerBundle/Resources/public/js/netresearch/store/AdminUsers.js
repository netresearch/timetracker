Ext.define('Netresearch.store.AdminUsers', {
	extend: 'Ext.data.Store',
	
	requires: [
   	    'Netresearch.model.User'
   	],
	
    autoDestroy: true,
    autoLoad: false,
    sortOnLoad: true,
    model: 'Netresearch.model.User',
    proxy: {
        type: 'ajax',
        url: url + 'getAllUsers',
        reader: {
            type: 'json',
            record: 'user'
        }
    },
    sorters: [{
            property: 'username',
            direction:'ASC'
    }]
});
