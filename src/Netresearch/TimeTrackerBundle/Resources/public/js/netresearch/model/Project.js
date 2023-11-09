Ext.define('Netresearch.model.Project', {
    extend: 'Ext.data.Model',
    fields: [
        {name: 'id', type: 'integer'},
        {name: 'name', type: 'string'},
        {name: 'customer', type: 'integer'},
        {name: 'ticket_system', type: 'integer'},
        {name: 'jiraId', type: 'string'},
        {name: 'jiraTicket', type: 'string'},
        {name: 'subtickets', type: 'array'},
        {name: 'active', type: 'boolean'},
        {name: 'additionalInformationFromExternal', type: 'boolean'},
        {name: 'global', type: 'boolean'},
        {name: 'project_lead', type: 'integer' },
        {name: 'technical_lead', type: 'integer' },
        {name: 'offer', type: 'string' },
        {name: 'billing', type: 'integer' },
        {name: 'cost_center', type: 'string' },
        {name: 'estimation', type: 'integer' },
        {name: 'estimationText', type: 'string' },
        {name: 'internalJiraProjectKey', type:'string'},
        {name: 'internalJiraTicketSystem', type:'integer'}
    ]
});
