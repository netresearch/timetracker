Ext.define('Netresearch.store.AdminProjects', {
	extend: 'Ext.data.Store',
	
	requires: [
   	    'Netresearch.model.Project'
   	],
	
    autoDestroy: true,
    autoLoad: true,
    sortOnLoad: true,
    model: 'Netresearch.model.Project',
    proxy: {
        type: 'ajax',
        url: url + 'getAllProjects',
        reader: {
            type: 'json',
            record: 'project'
        }
    },
    sorters: [{
            property: 'name',
            direction:'ASC'
    }, {
            property: 'customer',
            direction:'ASC'
    }]
});
