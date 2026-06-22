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
    sorters: [{
        property: 'name',
        direction:'ASC'
    }],

    /* Update customersData */
    reloadFromServer: function(callback) {
        Ext.Ajax.request({
            url: url + 'getCustomers',
            success: function(response) {
                let data = Ext.decode(response.responseText);
                //update the locally available customer data
                globalThis.customersData = data;
                callback();
            }
        });
    },

    /* Read data from json var in html source code */
    load: function(onlyActive) {
        const newData = [];
        let record;
        let c = 0;
        for (const key in customersData) {
            record = customersData[key].customer;

            if (!(record instanceof Ext.data.Model)) {
                record = Ext.ModelManager.create(record, this.model);
            }

            if (onlyActive) {
                if ('1' != record.data.active) {
                    continue;
                }
            }

            newData.push(record);
            this.add(record);
            c++;
        }

        this.loadRecords(newData, {"append": false});
        this.sort();
    }
});
