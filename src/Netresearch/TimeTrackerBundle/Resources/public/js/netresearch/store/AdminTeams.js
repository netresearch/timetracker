Ext.define('Netresearch.store.AdminTeams', {
	extend: 'Ext.data.Store',
	
	requires: [
   	    'Netresearch.model.Team'
   	],
	
    autoLoad: true,
    model: 'Netresearch.model.Team',
    proxy: {
        type: 'ajax',
        url: url + 'getAllTeams',
        reader: {
            type: 'json',
            record: 'team'
        }
    }
});
