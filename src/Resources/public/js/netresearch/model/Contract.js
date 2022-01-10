Ext.define('Netresearch.model.Contract', {
    extend: 'Ext.data.Model',
    fields: [
        {name: 'id', type: 'integer'},
        {name: 'user_id', type: 'integer'},
        {name: 'start', type: 'date', dateFormat: 'Y-m-d'},
        {name: 'end', type: 'date', dateFormat: 'Y-m-d'},
        {name: 'hours_0', type: 'integer'},
        {name: 'hours_1', type: 'integer'},
        {name: 'hours_2', type: 'integer'},
        {name: 'hours_3', type: 'integer'},
        {name: 'hours_4', type: 'integer'},
        {name: 'hours_5', type: 'integer'},
        {name: 'hours_6', type: 'integer'},
        {name: 'hours_7', type: 'integer'}
    ]
});
