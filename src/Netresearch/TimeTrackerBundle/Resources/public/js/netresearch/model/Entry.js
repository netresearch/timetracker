Ext.define('Netresearch.model.Entry', {
    extend: 'Ext.data.Model',
    fields: [
        {name: 'id', type: 'integer'},
        {name: 'date', type: 'date', dateFormat: 'd/m/Y'},
        {name: 'start', type: 'date', dateFormat: 'H:i'},
        {name: 'end', type: 'date', dateFormat: 'H:i'},
        {name: 'user'},
        {name: 'customer'},
        {name: 'project'},
        {name: 'activity'},
        {name: 'description', type: 'string'},
        {name: 'ticket', type: 'string'},
        {name: 'duration', type: 'date', dateFormat: 'H:i'},
        {name: 'worklog'},
        {name: 'class', type: 'integer'},
        {name: 'extTicketUrl'}
    ],
    validations: [
        {type: 'presence',  field: 'customer'},
        {type: 'presence',  field: 'project'},
        {type: 'presence',  field: 'activity'}
    ]
});
