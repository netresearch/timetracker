Ext.define('Netresearch.model.User', {
    extend: 'Ext.data.Model',
    fields: [
        {name: 'id', type: 'integer'},
        {name: 'username', type: 'string'},
        {name: 'abbr', type: 'string'},
        {name: 'locale', type: 'string'},
        {name: 'type', type: 'string'},
        {name: 'teams', type: 'array'}
    ]
});
