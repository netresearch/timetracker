/*
 * Help tab
 *
 * To display shortcuts and other help texts
 */
Ext.define('Netresearch.widget.Help', {
    extend: 'Ext.tab.Panel',

    initComponent: function() {

        var shortcutText = new Ext.form.Panel({
            frame: true,
            title: 'Shortcuts',
            bodyPadding: '20',
            width: 300,
            height: 150
        });

        /* Define container panel */
        var usagePanel = Ext.create('Ext.panel.Panel', {
            layout: 'fit',
            frame: true,
            title: 'Bedienung',
            collapsible: false,
            width: '100%',
            margin: '0 0 10 0',
            items: [{
                html:
                "<h3>TODO</h3>"
                + "<p>FIXME<p/>"
                ,
                xtype: "panel"
            }]
        });



        /* Define container panel */
        var shortcutPanel = Ext.create('Ext.panel.Panel', {
            layout: 'fit',
            frame: true,
            title: 'Shortcuts',
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
            title: 'Known Issues',
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
            title: 'Links',
            collapsible: false,
            width: '100%',
            margin: '0 0 10 0',
            items: [{
                html: 
                    "<h3>Projekt TimeTracker im JIRA</h3>"
                    + "<p><a href=\"https://jira.netresearch.de/jira/browse/TTT\">https://jira.netresearch.de/jira/browse/TTT</a></p>"
                    + "<br/>"
                    + "<h3>Dokumentation im Wiki</h3>"
                    + "<p><a href=\"http://wiki.nr/wiki/TimeTracker\">http://wiki.nr/wiki/TimeTracker</a></p>"
                    + "<br/>"
                    ,
                xtype: "panel"
            }]
        });


        var config = {
            title: 'Hilfe',
            items: [ usagePanel, shortcutPanel, issuesPanel, linksPanel ]
        };

        /* Apply settings */
        Ext.applyIf(this, config);
        this.callParent();
    }
});

