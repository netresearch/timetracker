/*
 * Help tab
 *
 * To display shortcuts and other help texts
 */
Ext.define('Netresearch.widget.Help', {
    extend: 'Ext.tab.Panel',

    /* Strings */
    _usageTitle: 'Usage',
    _shortcutsTitle: 'Shortcuts',
    _issuesTitle: 'Known Issues',
    _linksTitle: 'Links',
    _helpTitle: 'Help',

    initComponent: function() {

        /* Define container panel */
        var usagePanel = Ext.create('Ext.panel.Panel', {
            layout: 'fit',
            frame: true,
            title: this._usageTitle,
            collapsible: false,
            width: '100%',
            margin: '0 0 10 0',
            items: [{
                html:
                    "<h3>Add work log entry</h3>" +
                    "<p>Click the button <strong>Add Entry</strong>. Use the keyboard shortcut <strong>a</strong>.</p>" +
                    "<h3>Edit work log entry</h3>" +
                    "<p>Just click inside any field of any existing work log entry.</p>" +
                    "<h3>Delete work log entry</h3>" +
                    "<p>Right-click on an work log entry and select <strong>Delete</strong> from context menu." +
                    "Use keyboard shortcut <strong>d</strong> to delete focused work log entry.</p>" +
                    "<h3>Focus</h3>" +
                    "<p>Work log entry with focus has a yellow background." +
                    "Move the focus with keyboard <strong>up</strong> and <strong>down</strong> keys.</p>"
                ,
                xtype: "panel"
            }]
        });



        /* Define container panel */
        var shortcutPanel = Ext.create('Ext.panel.Panel', {
            layout: 'fit',
            frame: true,
            title: this._shortcutsTitle,
            collapsible: false,
            width: '100%',
            margin: '0 0 10 0',
            items: [{
                html: "<h3>Global</h3>"
                        + "<ul>"
                        + "<li><strong>ALT + 1<strong> : 1. Tab anwählen</li>"
                        + "<li><strong>ALT + 2<strong> : 2. Tab anwählen</li>"
                        + "<li><strong>ALT + 3<strong> : 3. Tab anwählen</li>"
                        + "<li><strong>ALT + 4<strong> : 4. Tab anwählen</li>"
                        + "<li><strong>ALT + 5<strong> : 5. Tab anwählen</li>"
                        + "<li><strong>ALT + 6<strong> : 6. Tab anwählen</li>"
                        + "<li><strong>ALT + 7<strong> : 7. Tab anwählen</li>"
                        + "</ul>"
                        + "<br />"
                        + "<h3>Tab &quot;Erfassung&quot;</h3>"
                        + "<ul>"
                        + "<li><strong>ALT + a<strong> : Neuen Eintrag anlegen (Add)</li>"
                        + "<li><strong>ALT + c<strong> : Selektierten/Letzten Eintrag fortsetzen (Continue)</li>"
                        + "<li><strong>ALT + d<strong> : Selektierten/Letzten Eintrag l&ouml;schen (Delete)</li>"
                        + "<li><strong>ALT + e<strong> : Selektierten/Letzten Eintrag editieren (Edit)</li>"
                        + "<li><strong>ALT + i<strong> : Info zu selektiertem/letztem Eintrag anzeigen (Info)</li>"
                        + "<li><strong>ALT + p<strong> : Letzten Eintrag verlängern auf aktuelle Zeit (Prolong)</li>"
                        + "<li><strong>ALT + r<strong> : Ansicht aktualisieren (Refresh)</li>"
                        + "<li><strong>?</strong> : Hilfe-Dialog aufrufen</li>"
                        + "</ul>"
                        + "<br />"
                        + "<h3>Tab &quot;Auswertung&quot;</h3>"
                        + "<ul>"
                        + "<li><strong>ALT + r<strong> : Ansicht aktualisieren (Refresh)</li>"
                        + "<li><strong>?</strong> : Hilfe-Dialog aufrufen</li>"
                        + "</ul>",
                xtype: "panel"
            }]
        });

        /* Define container panel */
        var issuesPanel = Ext.create('Ext.panel.Panel', {
            layout: 'fit',
            frame: true,
            title: this._issuesTitle,
            collapsible: false,
            width: '100%',
            margin: '0 0 10 0',
            items: [{
                html: "<ul>"
                        + "<li><strong>Uhrzeiten-Bug</strong>: Wenn man eine Zeit editiert, werden die Zahlen komplett beim Eintippen gelöscht. Workaround: Uhrzeiten beim Editieren immer komplett löschen und neu eingeben.</li><br/>"
                        + "<li><strong>Firefox/Adblocker und ?-Taste</strong>: Die Hilfetaste ? funktioniert nicht, wenn im Firefox Ad-Blocker installiert sind. Workaround: Chrome/Chromium/Opera benutzen oder Ad-Blocker deinstallieren.</li><br/>"
                        + "</ul>",
                xtype: "panel"
            }]
        });

        /* Define container panel */
        var linksPanel = Ext.create('Ext.panel.Panel', {
            layout: 'fit',
            frame: true,
            title: this._linksTitle,
            collapsible: false,
            width: '100%',
            margin: '0 0 10 0',
            items: [{
                html:
                    "<h3>Official project page</h3>"
                    + "<p><a href=\"https://github.com/netresearch/timetracker\">https://github.com/netresearch/timetracker</a></p>"
                    + "<br/>"
                    + "<h3>API documentation</h3>"
                    + "<p><a href=\"api.yml\">api.yml</a></p>"
                    + "<br/>"
                    + "<h3>JIRA cloud integration</h3>"
                    + "<p>To be used with the Greasemonkey browser extension: <a href=\"scripts/timeSummaryForJira\">scripts/timeSummaryForJira</a></p>"
                    ,
                xtype: "panel"
            }]
        });


        var config = {
            title: this._helpTitle,
            items: [ usagePanel, shortcutPanel, issuesPanel, linksPanel ]
        };

        /* Apply settings */
        Ext.applyIf(this, config);
        this.callParent();
    }
});

if ((undefined != settingsData) && (settingsData['locale'] == 'de')) {
    Ext.apply(Netresearch.widget.Help.prototype, {
        _usageTitle: 'Bedienung',
        _shortcutsTitle: 'Shortcuts',
        _issuesTitle: 'Bekannte Probleme',
        _linksTitle: 'Verweise',
        _helpTitle: 'Hilfe',
    });
}
