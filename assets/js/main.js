Ext.Loader.setConfig({
    enabled: true,
    paths: {
        'Ext': '../js/ext-js/src',
        'yourAppName': 'netresearch'
    }
});

// Link NR bundle to Namespace
Ext.Loader.setPath('Netresearch', '/build/js/netresearch');
Ext.Loader.setPath('Ext.ux.window', '/build/js');

/* Load necessary requirements */
Ext.require([
    'Ext.grid.*',
    'Ext.data.*',
    'Ext.util.*',
    'Ext.state.*',
    'Ext.container.Viewport',
    'Ext.form.*',
    'Ext.ux.window.Notification',
    'Netresearch.model.Entry',
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
    'Not logged in': 'Not logged in'
};

if ((undefined !== settingsData) && (settingsData.locale === 'de')) {
    strings = {
        'Not logged in': 'Nicht angemeldet'
    };
}

let ttt_container = undefined;
let ttt_tabpanel = undefined;
let ttt_items = [];

function switchTab(number) {
    number = Number.parseInt(number, 10);
    if ((0 > number) || (number > ttt_items.length)) {
        return;
    }

    const itemId = ttt_items[(number - 1)].getItemId();
    if (!ttt_tabpanel.isActiveTab(itemId))
        ttt_tabpanel.setActiveTab(ttt_tabpanel.child('#' + itemId));
    if (number === 1)
        ttt_items[0].getFocus();
}

function addTab(component) {
    const index = ttt_items.length + 1;
    component.title = index + ': ' + component.title;
    ttt_items.push(component);
}

Ext.onReady(function () {
    // Setup state manager
    Ext.state.Manager.setProvider(Ext.create('Ext.state.CookieProvider'));

    NetresearchWidgetTrackingLoadSettings(settingsData);
    const trackingWidget = Ext.create('Netresearch.widget.Tracking', {
        itemId: 'tracking',
        plugins: [cellEditing],
        autoRefreshInterval: true
    });

    addTab(trackingWidget);

    /*
     * Auswertung (Interpretation), Administration, Extras, Settings,
     * Controlling (Abrechnung) and Help have moved to the new SolidJS UI
     * (frontend/, served under /ui) and are reached via the shared header
     * navigation — see templates/partials/header.html.twig.
     */

    Ext.tip.QuickTipManager.init();

    ttt_tabpanel = Ext.create('Ext.tab.Panel', {
        id: 'main-content',
        region: 'center',
        activeTab: 0,
        items: ttt_items,
        listeners: {
            tabchange: function (tabPanel, newCard, oldCard, eOpts) {
                newCard.focus();
                if (ttt_tabpanel.isActiveTab('tracking')) {
                    ttt_items[0].getFocus();
                }
            }
        },
        isActiveTab: function (name) {
            return this.getActiveTab() == ttt_tabpanel.child('#' + name);
        }
    });

    /* Adopt the server-rendered shared header (templates/partials/header.html.twig) */
    const headerEl = Ext.get('page-header');

    /* Render whole layout into grid div */
    ttt_container = Ext.create('Ext.container.Viewport', {
        layout: 'border',
        renderTo: Ext.get('grid'),
        items: [{
            region: 'north',
            height: headerEl ? headerEl.getHeight() : 126,
            id: 'header',
            contentEl: 'page-header'
        },
            ttt_tabpanel
        ]
    });

    /* Key bindings */
    Ext.get(document).addKeyMap({ binding: [
        {
            key: Ext.EventObject.A,
            alt: true,
            handler: function () {
                if (ttt_tabpanel.isActiveTab('tracking'))
                    trackingWidget.addInlineEntry();
            },
            defaultEventAction: 'stopEvent'
        }, {
            key: Ext.EventObject.C,
            alt: true,
            handler: function () {
                if (ttt_tabpanel.isActiveTab('tracking'))
                    trackingWidget.continueSelectedEntry();
            },
            defaultEventAction: 'stopEvent'
        }, {
            key: Ext.EventObject.D,
            alt: true,
            handler: function () {
                if (ttt_tabpanel.isActiveTab('tracking'))
                    trackingWidget.deleteSelectedEntry();
            },
            defaultEventAction: 'stopEvent'
        }, {
            key: Ext.EventObject.E,
            alt: true,
            handler: function () {
                if (ttt_tabpanel.isActiveTab('tracking'))
                    trackingWidget.editSelectedEntry();
            },
            defaultEventAction: 'stopEvent'
        }, {
            key: Ext.EventObject.I,
            alt: true,
            handler: function () {
                if (ttt_tabpanel.isActiveTab('tracking'))
                    trackingWidget.showInfoOnSelectedEntry();
            },
            defaultEventAction: 'stopEvent'
        }, {
            key: Ext.EventObject.P,
            alt: true,
            handler: function () {
                if (ttt_tabpanel.isActiveTab('tracking'))
                    trackingWidget.prolongLastEntry();
            },
            defaultEventAction: 'stopEvent'
        }, {
            key: Ext.EventObject.R,
            alt: true,
            handler: function () {
                if (ttt_tabpanel.isActiveTab('tracking'))
                    trackingWidget.refresh();
            },
            defaultEventAction: 'stopEvent'
        }, {
            key: Ext.EventObject.X,
            alt: true,
            handler: function () {
                if (ttt_tabpanel.isActiveTab('tracking'))
                    trackingWidget.exportEntries();
            },
            defaultEventAction: 'stopEvent'
        }, {
            // Alt + Number is used for Tab switching
            key: [Ext.EventObject.ONE, Ext.EventObject.TWO, Ext.EventObject.THREE, Ext.EventObject.FOUR, Ext.EventObject.FIVE, Ext.EventObject.SIX, Ext.EventObject.SEVEN],
            alt: true,
            handler: function (key, e) {
                switchTab(Number.parseInt(key, 10) - 48);
                e.stopEvent();
            },
            defaultEventAction: 'stopEvent'
        }, {
            shift: true,
            key: 191,
            handler: function (key, e) {
                if (ttt_tabpanel.isActiveTab('tracking') && trackingWidget.isEditing()) {
                    return true;
                }

                if (ttt_tabpanel.isActiveTab('tracking')) {
                    trackingWidget.displayShortcuts();
                    e.stopEvent();
                }

                return true;
            },
            defaultEventAction: 'stopEvent'
        }
    ] });

    countTime();
    checkLoginStatus();
    trackingWidget.getFocus();
});


/**
 * Formats a duration from minutes into hours:minutes
 */
function formatDuration(duration, inDays) {
    const days = Math.floor(duration / (60 * 8) * 100) / 100;
    const hours = Math.floor(duration / 60);
    let minutes = duration % 60;
    if (minutes < 10) {
        minutes = '0' + minutes;
    }

    let text = hours + ':' + minutes;
    if ((inDays) && (days > 1.0)) {
        text += ' (' + days + ' PT)';
    }

    return text;
}

/**
 * Formats a duration as person-days only (e.g. '18.5 PT') for the Month badge.
 */
function formatDays(duration) {
    const days = Math.floor(duration / (60 * 8) * 100) / 100;

    return days + ' PT';
}

/*
 * Counts and displays worktime for today, this week and this month in the header
 */
function countTime() {
    Ext.Ajax.request({
        url: url + 'getTimeSummary',
        scope: this,
        success: function (response) {
            const data = Ext.decode(response.responseText);
            Ext.get('worktime-day').update(formatDuration(data.today.duration, false));
            Ext.get('worktime-week').update(formatDuration(data.week.duration, false));
            // Month shows person-days only; hours stay in the title for reference.
            Ext.get('worktime-month').update(formatDays(data.month.duration));
            Ext.get('worktime-month').set({ title: formatDuration(data.month.duration, false) });
        }
    });
}

/*
 * Checks login status via JSON API and updates the status indicator.
 * Polls every 90 seconds to keep status current.
 */
function applyLoginStatus(loggedIn) {
    // Update every user badge (desktop header + mobile drawer share .js-user-badge).
    const name = loggedIn ? settingsData.user_name : strings['Not logged in'];
    document.querySelectorAll('.js-user-badge').forEach(function (badge) {
        badge.classList.toggle('status_active', loggedIn);
        badge.classList.toggle('status_inactive', !loggedIn);
        const userNameEl = badge.querySelector('.js-user-name');
        if (userNameEl) {
            userNameEl.textContent = name;
        }
    });
}

function checkLoginStatus() {
    if (typeof statusUrlJson === 'undefined') {
        return;
    }

    Ext.Ajax.request({
        url: statusUrlJson,
        scope: this,
        success: function (response) {
            const data = Ext.decode(response.responseText);
            applyLoginStatus(!!data.loginStatus);
        },
        failure: function () {
            applyLoginStatus(false);
        }
    });

    // Poll every 90 seconds
    setTimeout(checkLoginStatus, 90000);
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

/**
 * Parses an Ext.Ajax error response and extracts a user-friendly message.
 * - For 422 responses with JSON bodies: concatenates violations or uses message
 * - Otherwise: returns response.responseText
 */
function parseAjaxError(response) {
    let data;
    let message = '';
    try {
        const ct = (response.getResponseHeader ? response.getResponseHeader('Content-Type') : '') || '';
        if (ct.indexOf('json') !== -1) {
            try { data = Ext.decode(response.responseText); } catch (e) { }
        }
        if (response.status === 422 && data) {
            if (data.violations && Ext.isArray(data.violations) && data.violations.length) {
                message = Ext.Array.map(data.violations, function (v) { return v.title || v.message || v; }).join('<br>');
            } else if (data.message) {
                message = data.message;
            }
        }
    } catch (e) { }
    if (!message) {
        if (data?.message) {
            message = data.message;
        } else {
            message = response.responseText;
        }
    }
    return { message: message, data: data };
}

/**
 * Shows a standardized error notification from an Ajax response.
 * Applies a fallback when the plain response text looks like a stack trace.
 */
function showAjaxFailure(title, response, fallbackMessage, shortTextThreshold) {
    const parsed = parseAjaxError(response);
    const threshold = (typeof shortTextThreshold === 'number') ? shortTextThreshold : 200;
    const isPlain = (parsed.message === response.responseText);
    let message = parsed.message;
    if ((!message) || (isPlain && response.responseText && response.responseText.length >= threshold)) {
        message = fallbackMessage || 'An error occurred.';
    }
    showNotification(title, message, false);
    return parsed;
}

let notification;

/**
 * Displays a toaster like message
 */
function showNotification(title, message, success) {
    let cls = 'ux-notification-light';
    if (false === success) {
        cls = 'ux-notification-light-error';
    }

    if ((undefined !== notification)
        && (null != notification)) {
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
function extractTicketPrefix(ticket) {
    const regexp = /([A-Za-z][A-Za-z0-9]*)-[0-9]+/;
    ticket = ticket.toUpperCase() + '';
    const result = ticket.match(regexp);
    if (!result) {
        return false;
    }

    return result[1];
}


function findProjects(customer, ticket) {
    // 1. Find all projects by this customer, if defined
    if ((null == customer) || (undefined == customer) || (1 > Number.parseInt(customer, 10))) {
        customer = 'all';
    } else {
        customer = Number.parseInt(customer, 10);
    }
    const projects = projectsData[customer];

    // 2. Filter projects by prefix, if defined
    if ((null == ticket) || (undefined == ticket)) {
        return projects;
    }

    // Support 2nd-trial mode: find projects without defined prefix
    let prefix = "";

    if (ticket == "") {
        let validProjects = [];
        let project;
        for (let key in projects) {
            project = projects[key];
            if ((undefined == project['jiraId']) || (null == project['jiraId']) || ("" == project['jiraId'])) {
                validProjects.push(project);
            }
        }
        return validProjects;
    }
    ticket = ticket.toUpperCase();

    prefix = extractTicketPrefix(ticket);
    if (prefix === false) {
        return projects;
    }

    let validProjects = [];
    let project;

    //find project by exact ticket number
    for (let projectKey in projects) {
        project = projects[projectKey];
        if (null == project['jiraTicket']) {
            continue;
        }
        for (let i = 0; i < project['subtickets'].length; i++) {
            if (project['subtickets'][i] == ticket) {
                validProjects.push(project);
            }
        }
    }
    if (validProjects.length > 0) {
        return validProjects;
    }

    //find project by ticket prefix
    const prefixesRegexp = /[ ,]*(?<prefix>[A-Za-z][A-Za-z0-9]*)[ ,]*/g;
    for (let key in projects) {
        project = projects[key];
        if ((undefined == project['jiraId']) || (null == project['jiraId']) || ("" == project['jiraId'])) {
            continue;
        }

        const projectPrefixes = [...project['jiraId'].matchAll(prefixesRegexp)];
        for (let i = 0; i < projectPrefixes.length; i++) {
            const projectPrefix = projectPrefixes[i][1];
            if (projectPrefix == prefix) {
                validProjects.push(project);
                break;
            }
        }
    }

    // If we searched with a prefix and didn't find something,
    // deliver all projects with an empty prefix defined
    if (("" != prefix) && (validProjects.length < 1))
        return findProjects(customer, "");

    return validProjects;
}
