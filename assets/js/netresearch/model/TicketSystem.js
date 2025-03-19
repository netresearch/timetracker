Ext.define('Netresearch.model.TicketSystem', {
    extend: 'Ext.data.Model',
    fields: [
        {name: 'id', type: 'integer'},
        {name: 'name', type: 'string'},
        {name: 'type', type: 'string'},
        {name: 'bookTime', type: 'boolean'},
        {name: 'url', type: 'string'},
        {name: 'login', type: 'string'},
        {name: 'password', type: 'string'},
        {name: 'publicKey', type: 'string'},
        {name: 'privateKey', type: 'string'},
        {name: 'ticketUrl', type: 'string'},
        {name: 'oauthConsumerKey', type: 'string'},
        {name: 'oauthConsumerSecret', type: 'string'}
    ]
});
