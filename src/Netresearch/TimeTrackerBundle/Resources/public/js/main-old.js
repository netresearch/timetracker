Ext.Loader.setConfig({
    enabled: true
});
Ext.Loader.setPath('Ext.ux', '../bundles/netresearchtimetracker/js/ext-js/ux');

Ext.require([
    'Ext.selection.CellModel',
    'Ext.grid.*',
    'Ext.data.*',
    'Ext.util.*',
    'Ext.state.*',
    'Ext.form.*',
    'Ext.ux.CheckColumn'
]);

Ext.onReady(function(){
	
	//var url = '/app_dev.php/';
	var url = '';
	var selectedRecord = null;

    function formatDate(value) {
        return value ? Ext.Date.dateFormat(value, 'd.m.Y') : '';
    }

    function formatTime(value) {
    	return value ? Ext.Date.dateFormat(value, 'H:i') : '';
    }

    function renderCombobox(combo) {
        return function(value) {
            var store = combo.store;
            var record = store.findRecord(combo.valueField, value);

            return record ? record.get(combo.displayField) : value;
        }
    }
    
    function round5(x) {
        return (x % 5) >= 2.5 ? parseInt(x / 5) * 5 + 5 : parseInt(x / 5) * 5;
    }
    
    function roundTime(time) {
    	return time ? time.setMinutes(round5(time.getMinutes())) : '';
    }
    
	function elapsed(difference) {
    	var minutes = 0;
    	var hours = 0;
    	var seconds = difference % 60;
    	
    	if(difference >= 60) {
    		difference = Math.floor(difference / 60);
    		minutes = difference % 60;
    		
    		if(difference >= 60) {
    			difference = Math.floor(difference / 60);
    			hours = difference;
    		}
    	}
    	
    	alert(hours + ' # ' + minutes + ' # ' + seconds);
	}  
    
    function addEntry() {
        var date = new Date();
        var hours = date.getHours();
        var minutes = round5(date.getMinutes());
        var time = ((hours < 10) ? "0" + hours : hours) + ":" + ((minutes < 10) ? "0" + minutes : minutes);
        //var time = null;
        
        if(store.getAt(0)) {
        	var record = store.getAt(0);
        	
        	if(record.data.date == date) {
        		alert("asd");
        	}
        	
        	elapsed(date.getMilliseconds() - record.data.to.getMilliseconds());
        	//alert(record.data.to.getMinutes() + ' # ' + date.getMinutes());
        }
        
        // Create a record instance through ModelManager
        var r = Ext.ModelManager.create({
            id: '',
            date: Ext.Date.clearTime(date),
            from: time,
            to: time,
            customer: '',
            project: '',
            activity: '',
            description: '',
            ticket: '-',
            booked: 0
        }, 'Entry');
        
        store.insert(0, r);
        cellEditing.startEditByPosition({row: 0, column: 1});
    }
    
    // Define entry model
    Ext.define('Entry', {
        extend: 'Ext.data.Model',
        fields: [
            {name: 'id', type: 'integer'},
            {name: 'date', type: 'date', dateFormat: 'd/m/Y'},
            {name: 'from', type: 'date', dateFormat: 'H:i'},
            {name: 'to', type: 'date', dateFormat: 'H:i'},
            {name: 'customer'},
            {name: 'project'},
            {name: 'activity'},
            {name: 'description', type: 'string'},
            {name: 'ticket', type: 'string'},
            {name: 'booked', type: 'bool'}
        ]
    });

    // Define customer model
    Ext.define('Customer', {
        extend: 'Ext.data.Model',
        fields: [
            {name: 'id', type: 'integer'},
            {name: 'name', type: 'string'}
        ]
    });

    // Define project model
    Ext.define('Project', {
        extend: 'Ext.data.Model',
        fields: [
            {name: 'id', type: 'integer'},
            {name: 'name', type: 'string'}
        ]
    });

    // Define type model
    Ext.define('Type', {
        extend: 'Ext.data.Model',
        fields: [
            {name: 'id', type: 'integer'},
            {name: 'name', type: 'string'}
        ]
    });
    
    // create the Data Store
    var store = Ext.create('Ext.data.Store', {
        autoDestroy: true,
        model: 'Entry',
        proxy: {
            type: 'ajax',
            /* @todo: Generic way... */
            url: url + 'getData',
            reader: {
                type: 'json',
                record: 'entry'
            }
        },
        sorters: [{
            property: 'id',
            direction:'DESC'
        }],
        listeners: {
        	update: function(store, record, operation) {
                if (record.dirty) {
                	record.data.from = roundTime(record.data.from);
                	record.data.to = roundTime(record.data.to);
                	
                    Ext.Ajax.request({
                        url: url + 'save',
                        params: record.data,
                        success: function(response) {
                            data = Ext.decode(response.responseText);

                            if (data.result) {
                                record.data.id = data.result;
                                record.commit();
                                grid.getView().refresh();
                            }
                        }
                    });
                }
        	},
        }
    });

    var customerStore = Ext.create('Ext.data.Store', {
        model: 'Customer',
        proxy: {
            type: 'ajax',
            url: url + 'getCustomers',
            reader: {
                type: 'json',
                record: 'customer'
            }
        },
    });

    var projectStore = Ext.create('Ext.data.Store', {
        model: 'Project',
        proxy: {
            type: 'ajax',
            url: url + 'getProjects',
            reader: {
                type: 'json',
                record: 'project'
            }
        },
    });

    var typeStore = Ext.create('Ext.data.Store', {
        model: 'Type',
        proxy: {
            type: 'ajax',
            url: url + 'getTypes',
            reader: {
                type: 'json',
                record: 'type'
            }
        },
    });

    customerStore.load();
    projectStore.load();
    typeStore.load();

    var customerEditor = new Ext.form.ComboBox({
        typeAhead: true,
        store: customerStore,
        triggerAction: 'all',
        selectOnTab: true,
        selectOnFocus: true,
        forceSelection: true,
        lazyRender: true,
        displayField: 'name',
        valueField: 'id',
        listClass: 'x-combo-list-small',
        listeners: {
        	select: function(field, value) {
        		selectedRecord.data.project = '';
        		grid.getView().refresh();
        		
        		projectStore.load({ 
        			params: { 
        				customer: value[0].data.id
        			}
        		});
        	}
        }
    });

    var projectEditor = new Ext.form.ComboBox({
        typeAhead: true,
        store: projectStore,
        queryMode: 'local',
        selectOnTab: true,
        selectOnFocus: true,
        forceSelection: true,
        lazyRender: true,
        displayField: 'name',
        valueField: 'id',
        listClass: 'x-combo-list-small',
        listeners: {
        	select: function(field, value) {
        		if(selectedRecord != null) {
                    Ext.Ajax.request({
                        url: url + 'getCustomer',
                        params: {
                        	project: value[0].data.id
                        },
                        success: function(response) {
                            data = Ext.decode(response.responseText);

                            selectedRecord.data.customer = data.customer;
                			selectedRecord.commit();
                            grid.getView().refresh();
                        }
                    });
        		}
        	}
        }
    });

    var typeEditor = new Ext.form.ComboBox({
        xtype: 'combobox',
        typeAhead: true,
        store: typeStore,
        triggerAction: 'all',
        selectOnTab: true,
        selectOnFocus: true,
        forceSelection: true,
        lazyRender: true,
        displayField: 'name',
        valueField: 'id',
        listClass: 'x-combo-list-small'
    });

    var cellEditing = Ext.create('Ext.grid.plugin.CellEditing', { clicksToEdit: 1 });

    // Create grid
    var grid = Ext.create('Ext.grid.Panel', {
        store: store,
        columns: [{
            header: 'Id',
            dataIndex: 'id',
            hidden: true
        }, {
            header: 'Datum',
            dataIndex: 'date',
            renderer: formatDate,
            width: 90,
            field: {
                xtype: 'datefield',
                format: 'd/m/Y',
                allowBlank: false
            }
        }, {
            header: 'Von',
            dataIndex: 'from',
            renderer: formatTime,
            width: 75,
            field: {
                xtype: 'timefield',
                allowBlank: false,
                format: 'H:i',
                increment: 5
            }
        }, {
            header: 'Bis',
            dataIndex: 'to',
            renderer: formatTime,
            width: 75,
            field: {
                xtype: 'timefield',
                allowBlank: false,
                format: 'H:i',
                increment: 5
            }
        }, {
            header: 'Kunde',
            dataIndex: 'customer',
            width: 100,
            field: customerEditor,
            renderer: renderCombobox(customerEditor)
        }, {
            header: 'Projekt',
            dataIndex: 'project',
            width: 150,
            field: projectEditor, 
            renderer: renderCombobox(projectEditor) 
        }, { 
            header: 'Tätigkeit',
            dataIndex: 'activity',
            flex: 1,
            field: typeEditor, 
            renderer: renderCombobox(typeEditor) 
        }, {
            header: 'Beschreibung',
            dataIndex: 'description',
            flex: 1,
            field: {
                allowBlank: false
            }
        }, {
            header: 'Fall',
            dataIndex: 'ticket',
            width: 125,
            field: {
                allowBlank: true
            }
        }, {
            xtype: 'checkcolumn',
            header: 'In JIRA gebucht?',
            dataIndex: 'booked',
            width: 100
        }],
        selModel: {
            selType: 'cellmodel'
        },
        renderTo: 'grid',
        width: '100%',
        height: '100%',
        title: 'TimeTracker',
        frame: true,
        tbar: [{
            text: 'Eintrag hinzufügen',
            tooltip: 'Shortcut (a)',
            iconCls: 'icon-add',
            handler : addEntry
        }, {
        	text: 'Export',
        	handler: function() {
        		// @todo: Generic path...
                window.location.href = 'export';
        	}
        }, {
            text: 'Logout',
            tooltip: 'Test',
            handler: function() {
                // @todo: Generic path...
                window.location.href = 'logout';
            }
        }],
        // paging bar on the bottom
        bbar: Ext.create('Ext.PagingToolbar', {
            store: store,
            displayInfo: true,
            displayMsg: 'Zeige Einträge {0} - {1} von {2}',
            emptyMsg: "Keine Einträge gefunden",
        }),
        plugins: [cellEditing],
        listeners: {
        	itemclick: function(grid, record) {
        		selectedRecord = record;
        	}
        }
    });
    
    Ext.get(document).on('keydown', function(e) {
    	switch(e.getKey()) {
    		case 65:
    			addEntry();
    			break;
    	}
    });
    
    store.load();
})
