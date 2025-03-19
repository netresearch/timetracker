Ext.define('Netresearch.store.Activities', {
	extend: 'Ext.data.Store',
	
	requires: [
   	    'Netresearch.model.Activity'
   	],

    autoLoad: false,
    model: 'Netresearch.model.Activity',
    proxy: {
        type: 'ajax',
        url: url + 'getActivities',
        reader: {
            type: 'json',
            record: 'activity'
        }
    },
    sorters: [{
            property: 'name',
            direction:'ASC'
    }]
});
