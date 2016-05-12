Ext.define('Netresearch.model.Preset', {
    extend: 'Ext.data.Model',
    fields: [
        {name: 'id', type: 'integer'},
        {name: 'name', type: 'string'},
        {name: 'customer'},
        {name: 'project'},
        {name: 'activity'},
        {name: 'description', type: 'string'}
    ]
});
