Ext.define('Netresearch.model.Activity', {
    extend: 'Ext.data.Model',
    fields: [
        {name: 'id', type: 'integer'},
        {name: 'name', type: 'string'},
        {name: 'needsTicket', type: 'boolean'},
        {name: 'factor', type: 'float'}
    ]
});
