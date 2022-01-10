Ext.define('Netresearch.model.Customer', {
    extend: 'Ext.data.Model',
    fields: [
        {name: 'id', type: 'integer'},
        {name: 'name', type: 'string'},
        {name: 'active', type: 'boolean'},
        {name: 'global', type: 'boolean'},
        {name: 'teams', type: 'array'}
    ]
});
