Ext.define('Netresearch.store.Customers', {
	extend: 'Ext.data.Store',
	
	requires: [
   	    'Netresearch.model.Customer'
   	],
	
    autoLoad: false,
    model: 'Netresearch.model.Customer',
    proxy: {
        type: 'memory',
        reader: {
            type: 'json',
            record: 'customer'
        }
    },
    /* Read data from json var in html source code */
    load: function(onlyActive) {
        var newData = [], record;
        // console.log("Loading up to " + customersData.length + " customers. " + onlyActive);
        var c = 0;
        for (var key in customersData) {
            record = customersData[key].customer;

            if (!(record instanceof Ext.data.Model)) {
                record = Ext.ModelManager.create(record, this.model);
            }

            if (onlyActive) {
                if ('1' != record.data.active) {
                    // console.log(record.get('id') + " is inactive!");
                    continue;
                }
            }

            newData.push(record);
            this.add(record);
            c++;
        }
        console.log("Loaded " + c + " customers.");
        this.loadRecords(newData, {"append": false});
    }
});
