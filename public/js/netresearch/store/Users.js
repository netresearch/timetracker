Ext.define('Netresearch.store.Users', {
	extend: 'Ext.data.Store',
	
	requires: [
   	    'Netresearch.model.User'
   	],
	
    autoDestroy: true,
    autoLoad: false,
    model: 'Netresearch.model.User',
    proxy: {
        type: 'ajax',
        url: url + 'getUsers',
        reader: {
            type: 'json',
            record: 'user'
        }
    }
});