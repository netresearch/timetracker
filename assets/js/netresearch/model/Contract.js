Ext.define('Netresearch.model.Contract', {
    extend: 'Ext.data.Model',
    fields: [
        {name: 'id', type: 'integer'},
        {name: 'user_id', type: 'integer'},
        {name: 'start', type: 'date', dateFormat: 'Y-m-d'},
        {name: 'end', type: 'date', dateFormat: 'Y-m-d'},
        {name: 'hours_0', type: 'float'},
        {name: 'hours_1', type: 'float'},
        {name: 'hours_2', type: 'float'},
        {name: 'hours_3', type: 'float'},
        {name: 'hours_4', type: 'float'},
        {name: 'hours_5', type: 'float'},
        {name: 'hours_6', type: 'float'},
        {name: 'hours_7', type: 'float'}
    ]
});
