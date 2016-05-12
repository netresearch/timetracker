Ext.define('Netresearch.store.Activities', {
	extend: 'Ext.data.Store',
	
	requires: [
   	    'Netresearch.model.Activity'
   	],
	
    autoLoad: true,
    model: 'Netresearch.model.Activity',
    proxy: {
        type: 'memory',
        reader: {
            type: 'json',
            record: 'activity'
        }
    },
    sorters: [{
            property: 'name',
            direction:'ASC'
    }],

    /* Read data from json var in html source code */
    load: function() {
        var newData = [], record;
        for (var key in activityData) {
            record = activityData[key].activity;

            if (!(record instanceof Ext.data.Model)) {
                record = Ext.ModelManager.create(record, this.model);
            }
            newData.push(record);
        }

        // False means: remove old data from store
        this.loadRecords(newData, {addRecords: false});
    }
});
