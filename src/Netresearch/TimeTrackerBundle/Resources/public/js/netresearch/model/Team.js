Ext.define('Netresearch.model.Team', {
    extend: 'Ext.data.Model',
    fields: [
        {name: 'id', type: 'integer'},
        {name: 'name', type: 'string'},
        {name: 'lead_user_id', type: 'integer'}
    ]
});
