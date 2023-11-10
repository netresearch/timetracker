Ext.define('Netresearch.store.Projects', {
    extend: 'Ext.data.Store',

    requires: [
           'Netresearch.model.Project'
       ],

    autoDestroy: true,
    autoLoad: false,
    currentCustomer: null,
    model: 'Netresearch.model.Project',
    proxy: {
        type: 'memory',
        reader: {
            type: 'json',
            record: 'projects'
        }
    },

    /* Update projectsData */
    reloadFromServer: function(callback) {
        Ext.Ajax.request({
            url: url + 'getProjectStructure',
            success: function(response) {
                let data = Ext.decode(response.responseText);
                //update the locally available project data
                projectsData = data;
                callback();
            }
        });
    },

    /* Read data from json var in html source code */
    load: function(options) {
        return this.loadData(projectsData, null, null, false);
    },
    /* Read data from json var in html source code */
    loadData: function(data, customer, ticket, onlyActive) {

        // Check if we are valid already
        if (this.currentCustomer && (this.currentCustomer == parseInt(customer)))
            return;

        var projects = findProjects(customer, ticket)
        if (!projects)
            projects = new Array();

        // merge empty prefix projects, if any
        if ((null !== ticket) && (undefined !== ticket) && (ticket.length > 0)) {
            projects2 = findProjects(customer, "");
            if (projects2) {
                // console.log("Merged " + projects2.length + " projects with empty prefixes");
                projects = projects.concat(projects2);
            }
        }

        this.currentCustomer = parseInt(customer);

        var newData = [], record;

        if (! projects.length) {
            this.removeAll();
            return;
        }

        for (var key in projects) {
            record = projects[key];

            if (!(record instanceof Ext.data.Model)) {
                record = Ext.ModelManager.create(record, this.model);
            }

            if (onlyActive) {
                if ('1' != record.data.active) {
                    // console.log("Project " + record.data.id + " is inactive.");
                    continue;
                }
            }

            newData.push(record);

            this.add(record);

            // if (customer != 'all')
            //    console.log("Loading project " + record.data.id + " (" + record.data.name + " of customer " + record.data.customer + ") for customer " + customer);
        }

        this.loadRecords(newData, {"append": false});
    }
});
