Ext.Loader.setConfig({
    enabled: true,
    paths: {
        'Ext': '../bundles/netresearchtimetracker/js/ext-js/src',
        'yourAppName': 'netresearch'
    }
});

// Link NR bundle to Namespace
Ext.Loader.setPath('Netresearch', '../bundles/netresearchtimetracker/js/netresearch');
Ext.Loader.setPath('Ext.ux.window', '../bundles/netresearchtimetracker/js');

/* Load necessary requirements */
Ext.require([
    'Ext.grid.*',
    'Ext.data.*',
    'Ext.util.*',
    'Ext.state.*',
    'Ext.container.Viewport',
    'Ext.form.*',
    'Ext.ux.window.Notification',
    'Netresearch.widget.Tracking',
    'Netresearch.widget.Interpretation',
    'Netresearch.widget.Extras',
    'Netresearch.widget.Settings',
    'Netresearch.widget.Controlling',
    'Netresearch.widget.Admin',
    'Netresearch.widget.Help'
]);

/* Define how many clicks are needed to active inline editing of tracking grid entries */
const cellEditing = Ext.create('Ext.grid.plugin.CellEditing', {
    clicksToEdit: 1
});

let strings = {
    'Today': 'Today',
    'Week' : 'Week',
    'Month': 'Month',
    'Monthly overview': 'Monthly overview',
    'Logout': 'Logout'
};

if ((undefined !== settingsData) && (settingsData.locale === 'de')) {
    strings = {
        'Today': 'Heute',
        'Week' : 'Woche',
        'Month': 'Monat',
        'Monthly overview': 'Monatsauswertung',
        'Logout': 'Logout'
    };
}

let ttt_container = undefined;
let ttt_tabpanel = undefined;
let ttt_items = [];

function switchTab(number) {
    number = parseInt(number);
    if ((0 > number) || (number > ttt_items.length)) {
        return;
    }

    const itemId = ttt_items[(number-1)].getItemId();
    if (! ttt_tabpanel.isActiveTab(itemId))
        ttt_tabpanel.setActiveTab(ttt_tabpanel.child('#' + itemId));
    if (number === 1)
        ttt_items[0].getFocus();
}

function addTab(component) {
    const index = ttt_items.length + 1;
    component.title = index + ': ' + component.title;
    ttt_items.push(component);
}

Ext.onDocumentReady(function() {
    // Setup state manager
    Ext.state.Manager.setProvider(Ext.create('Ext.state.CookieProvider'));

    const trackingWidget = Ext.create('Netresearch.widget.Tracking',
        { itemId: 'tracking', plugins: [cellEditing] }
    );

    const interpretationWidget = Ext.create('Netresearch.widget.Interpretation', { itemId: 'interpretation' });
    const extrasWidget = Ext.create('Netresearch.widget.Extras', { itemId: 'extras'});
    const settingsWidget = Ext.create('Netresearch.widget.Settings', { itemId: 'settings' });

    addTab(trackingWidget);
    addTab(interpretationWidget);
    addTab(extrasWidget);
    addTab(settingsWidget);

    /* Show admin tab, if user is admin */
    if ((undefined !== settingsData) && (settingsData['type'] === 'PL')) {
        const adminWidget = Ext.create('Netresearch.widget.Admin', { itemId: 'admin' });
        addTab(adminWidget);
    }

    if ((undefined !== settingsData) && (settingsData['type'] !== 'DEV')) {
        const controllingWidget = Ext.create('Netresearch.widget.Controlling', { itemId: 'controlling' });
        addTab(controllingWidget);
    }

    const helpWidget = Ext.create('Netresearch.widget.Help', { itemId: 'help' });
    addTab(helpWidget);

    Ext.tip.QuickTipManager.init();

    ttt_tabpanel = Ext.create('Ext.tab.Panel', {
        region: 'center',
        activeTab: 0,
        items: ttt_items,
        listeners: {
            tabchange: function(tabPanel, newCard, oldCard, eOpts) {
                newCard.focus();
                if (ttt_tabpanel.isActiveTab('tracking')) {
                    ttt_items[0].getFocus();
                }
            }
        },
        isActiveTab: function(name) {
            return this.getActiveTab() == ttt_tabpanel.child('#' + name);
        }
    });

    /* Render whole layout into grid div */
    ttt_container = Ext.create('Ext.container.Viewport', {
        layout: 'border',
        renderTo: Ext.get('grid'),
        items: [{
            region: 'north',
            height: 100,
            id: 'header',
            html: (globalConfig.header_url != '' ? '<iframe id="nrnavi" src="' + globalConfig.header_url + '"></iframe>' : '')
                    + '<div><img id="logo" src="' + globalConfig.logo_url + '" title="logo" alt="logo"></div>'
                    + (typeof statusUrlHtml !== 'undefined'
                        ? '<iframe id="statusfrm" src="' + statusUrlHtml + '"></iframe>' : '')
                    + '<div id="worktime">'
                    + '<span id="worktime-day">' + strings['Today'] + ': 0:00</span> / <span id="worktime-week">' + strings['Week'] + ': 0:00</span> / <span id="worktime-month">' + strings['Month'] + ': 0:00</span>'
                    + (typeof globalConfig.monthly_overview_url !== 'undefined' && globalConfig.monthly_overview_url != null && globalConfig.monthly_overview_url != ''
                        ? '<br><span id="sumlink"><a href="' + globalConfig.monthly_overview_url + settingsData.user_name + '" target="_new">' + strings['Monthly overview'] + '</a></span>' : '')
                    + '</div>'
                    + (typeof logoutUrlHtml !== 'undefined'
                        ? '<div id="logout"><a href="' + logoutUrlHtml + '">' + strings['Logout'] + '</a></div>' : '')
        },
            ttt_tabpanel
        ]
    });

    /* Key bindings */
    const keyMap = new Ext.util.KeyMap(Ext.get(document), [
        {
            key: Ext.EventObject.A,
            alt: true,
            handler: function() {
                if (ttt_tabpanel.isActiveTab('tracking'))
                    trackingWidget.addInlineEntry();
            },
            defaultEventAction: 'stopEvent'
        }, {
            key: Ext.EventObject.C,
            alt: true,
            handler: function() {
                if (ttt_tabpanel.isActiveTab('tracking'))
                    trackingWidget.continueSelectedEntry();
            },
            defaultEventAction: 'stopEvent'
        }, {
            key: Ext.EventObject.D,
            alt: true,
            handler: function() {
                if (ttt_tabpanel.isActiveTab('tracking'))
                    trackingWidget.deleteSelectedEntry();
            },
            defaultEventAction: 'stopEvent'
        }, {
            key: Ext.EventObject.E,
            alt: true,
            handler: function() {
                if (ttt_tabpanel.isActiveTab('tracking'))
                    trackingWidget.editSelectedEntry();
            },
            defaultEventAction: 'stopEvent'
        }, {
            key: Ext.EventObject.I,
            alt: true,
            handler: function() {
                if (ttt_tabpanel.isActiveTab('tracking'))
                    trackingWidget.showInfoOnSelectedEntry();
            },
            defaultEventAction: 'stopEvent'
        }, {
            key: Ext.EventObject.P,
            alt: true,
            handler: function() {
                if (ttt_tabpanel.isActiveTab('tracking'))
                    trackingWidget.prolongLastEntry();
            },
            defaultEventAction: 'stopEvent'
        }, {
            key: Ext.EventObject.R,
            alt: true,
            handler: function() {
                if (ttt_tabpanel.isActiveTab('tracking'))
                    trackingWidget.refresh();
                if (ttt_tabpanel.isActiveTab('interpretation'))
                    interpretationWidget.refresh();
            },
            defaultEventAction: 'stopEvent'
        }, {
            key: Ext.EventObject.X,
            alt: true,
            handler: function() {
                if (ttt_tabpanel.isActiveTab('tracking'))
                    trackingWidget.exportEntries();
            },
            defaultEventAction: 'stopEvent'
        }, {
            // Alt + Number is used for Tab switching
            key: [ Ext.EventObject.ONE, Ext.EventObject.TWO, Ext.EventObject.THREE, Ext.EventObject.FOUR, Ext.EventObject.FIVE, Ext.EventObject.SIX, Ext.EventObject.SEVEN ],
            alt: true,
            handler: function(key, e) {
                switchTab(parseInt(key) - 48);
                e.stopEvent();
            },
            defaultEventAction: 'stopEvent'
        }, {
            shift: true,
            key: 191,
            handler: function(key, e) {
                if (ttt_tabpanel.isActiveTab('tracking') && trackingWidget.isEditing()) {
                    return true;
                }

                if (ttt_tabpanel.isActiveTab('tracking')) {
                    trackingWidget.displayShortcuts();
                    e.stopEvent();
                }

                if (ttt_tabpanel.isActiveTab('interpretation')) {
                    interpretationWidget.displayShortcuts();
                    e.stopEvent();
                }

                return true;
            },
            defaultEventAction: 'stopEvent'
        }
    ]);

    countTime();
    trackingWidget.getFocus();
});


/**
 * Formats a duration from minutes into hours:minutes
 */
function formatDuration(duration, inDays)
{
    const days = Math.floor(duration / (60*8) * 100) / 100;
    const hours = Math.floor(duration / 60);
    let minutes = duration % 60;
    if (minutes < 10) {
        minutes = '0' + minutes;
    }

    let text = hours + ':' + minutes;
    if ((inDays)&&(days > 1.0)) {
        text += ' (' + days + ' PT)';
    }

    return text;
}

/*
 * Counts and displays worktime for today, this week and this month in the upper right corner
 */
function countTime() {
    Ext.Ajax.request({
        url: url + 'getTimeSummary',
        scope: this,
        success: function(response) {
            const data = Ext.decode(response.responseText);
            Ext.get('worktime-day').update(strings['Today'] + ': ' + formatDuration(data.today.duration, false));
            Ext.get('worktime-week').update(strings['Week'] + ': ' + formatDuration(data.week.duration, false));
            Ext.get('worktime-month').update(strings['Month'] + ': ' + formatDuration(data.month.duration, true));
        }
    });
}

/*
 * Handles redirects
 * - Status code 403: Javascript Redirect
 * - everything else: Error message
 */
function handleRedirect(response, title, message) {
    if (response.status === 403) {
        showNotification(title, message, false);
        setTimeout("window.location.href = '" + response.responseText + "'", 2000);
    } else {
        showNotification('Fehler', response.responseText);
    }
}

var notification = undefined;

/**
 * Displays a toaster like message
 */
function showNotification(title, message, success)
{
    let cls = 'ux-notification-light';
    if (false === success) {
        cls = 'ux-notification-light-error';
    }

    if ((undefined !== notification)
        && (null != notification))
    {
        notification.hide();
        notification = undefined;
    }

    notification = Ext.create('widget.uxNotification', {
        title: title,
        position: 't',
        manager: 'instructions',
        cls: cls,
        plain: true,
        closable: false,
        autoHideDelay: 5000,
        autoHide: true,
        spacing: 20,
        width: 400,
        autoHeight: true,
        html: message,
        slideBackDuration: 700,
        slideInAnimation: 'bounceOut',
        slideBackAnimation: 'easeIn'
    });

    notification.show();
}

/**
 * Returns the prefix of a given ticket
 */
function extractTicketPrefix(ticket)
{
    const regexp = /([A-Za-z]+[A-Za-z0-9]*)-[0-9]+/;
    ticket = ticket.toUpperCase() + '';
    const result = ticket.match(regexp);
    if (!result) {
        return false;
    }

    return result[1];
}


function findProjects(customer, ticket)
{
    // 1. Find all projects by this customer, if defined
    if ((null == customer) || (undefined == customer) || (1 > parseInt(customer)))
        customer = 'all';
    else
        customer = parseInt(customer);
    var projects = projectsData[customer];

    // 2. Filter projects by prefix, if defined
    if ((null == ticket) || (undefined == ticket)) {
        return projects;
    }

    // Support 2nd-trial mode: find projects without defined prefix
    let prefix = "";
    const regexp = /([ ,]+)?([A-Za-z][A-Za-z0-9]*)([ ,]+)?/;

    if (ticket == "") {
        prefix = "";
    } else {
        prefix = extractTicketPrefix(ticket);
        if (prefix === false)
            return projects;
    }

    let validProjects = [];
    let value;
    for (let key in projects) {
        value = projects[key];

        if (prefix == "") {
            if ((undefined == value['jiraId']) || (null == value['jiraId']) || ("" == value['jiraId'])) {
                validProjects.push(value);
            }
            continue;
        }

        if ((undefined == value['jiraId']) || (null == value['jiraId']))
            continue;

        if (value['jiraId'] == prefix) {
            validProjects.push(value);
            continue;
        }

        const result = value['jiraId'].match(regexp);
        if (!result) {
            continue;
        }

        for (var i=1; i < result.length; i++) {
            if (result[i] == prefix) {
                // console.log("Found project " + value['id'] + ", " + value['name'] + " of customer " + value['customer'] + " by prefix " + prefix);
                validProjects.push(value);

            }
        }
    }

    // If we searched with a prefix and didn't find something,
    // deliver all projects with an empty prefix defined
    if (("" != prefix) && (validProjects.length < 1))
        return findProjects(customer, "");

    return validProjects;
}
