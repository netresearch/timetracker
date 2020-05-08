// ==UserScript==
// @name     Timetracker times in JIRA Cloud
// @version  2
// @description Display tracked times on the JIRA Cloud ticket details page
// @include /https:\/\/.*\.atlassian\.net\/browse\/.*/
// @icon     https://timetracker/favicon.ico
// @grant    none
// ==/UserScript==


var ticket = null || window.location.href.split('/').slice(-1)[0];
var timetrackerUrl = 'https://timetracker/getTicketTimeSummary/' + ticket;

window.addEventListener('load', function () {

    var list = document.getElementById('viewissuesidebar');
    var labJira = true;

    if (list === null) {
        labJira = false;
        list = document.querySelector(
            "[data-test-id='issue.views.issue-base.context.context-items.primary-items']"
        );
    }

    createButton(labJira, list);

}, false);

function createButton(labJira, list) {

    var button = document.createElement('button');
    button.innerText = 'Zeiten aus Timetracker laden';
    button.style.marginBottom = '35px';
    button.style.background = "rgb(0, 82, 204)";
    button.style.color = "rgb(255, 255, 255)";
    button.style.height = '30px';

    if (labJira == true) {
        button.style.marginTop = '15px';
    }

    button.addEventListener('click', function () {
        getTimeSummary.bind({
            list: list,
            url: timetrackerUrl,
            labJira: labJira,
            button: button,
        })();
    });
    list.appendChild(button);
}


function getNewDiv(content, div, list = false) {
    var divElement = document.createElement('div');

    if (list == true) {
        divElement.style.paddingLeft = '10px';
    }

    divElement.style.marginTop = '5px';
    var nodeContent = document.createTextNode(content);
    divElement.appendChild(nodeContent);
    div.appendChild(divElement);
}

function createTimeSummary(list, data) {

    var headline = ['Gesamtzeit:\xa0\xa0', 'T\u00e4tigkeiten:', 'Personen:'];
    var title = document.querySelector(
        "[data-test-id='issue.views.issue-base.context.context-items.primary-items']"
    ).childNodes[0].childNodes[0].childNodes[0];
    var cloneTitle = title.cloneNode(true);
    var titleText = cloneTitle.childNodes[0].textContent = 'Aufgewendete Zeit';

    var divTitle = document.createElement('div');
    divTitle.appendChild(cloneTitle);
    list.appendChild(divTitle);

    var newDiv = document.createElement('div');
    newDiv.style.marginBottom = '20px';
    newDiv.style.display = 'flex';
    newDiv.appendChild.felxdirection = 'row';
    newDiv.style.fleyWrap = 'wrap';
    newDiv.style.width = '100%';

    var rowOne = document.createElement('div');
    rowOne.className = 'one';
    rowOne.style.display = 'flex';
    rowOne.style.flexDirection = 'column';
    rowOne.style.flexBasis = '100%';
    rowOne.style.flex = 'inherit';

    var rowtwo = rowOne.cloneNode(true);
    rowtwo.className = 'two';

    var i = 0;
    Object.entries(data).forEach((value) => {
        time = false || value[1].time;

        if (!time) {
            getNewDiv(headline[i++], rowOne);
            getNewDiv('\xa0', rowtwo, true);

            Object.keys(value[1]).forEach((key, index) => {
                name = key;
                content = value[1][key].time;
                getNewDiv(key + ": ", rowOne, true);
                getNewDiv(content, rowtwo, true);
            })
        } else {
            getNewDiv(headline[i++], rowOne);
            getNewDiv(value[1].time, rowtwo, true);
        }
    })
    newDiv.appendChild(rowOne);
    newDiv.appendChild(rowtwo);
    return newDiv;
}

function createLabJiraTimeSummay(list, data) {
    var headline = ["<b>Gesamtzeit:\xa0\xa0</b>", "<b>T\u00e4tigkeiten:</b>", "<b>Personen:</b>"];

    var title = document.getElementById('datesmodule');
    var cloneTitle = title.cloneNode(true);
    cloneTitle.childNodes[0].childNodes[1].textContent = 'Aufgewendete Zeit';
    var i = 0;

    var liEl = cloneTitle.childNodes[1].childNodes[1].childNodes[1];

    Object.entries(data).forEach((value) => {
        time = false || value[1].time;

        if (!time) {
            var newHeadline = createNewContent(headline[i++], '\xa0', cloneTitle);
            liEl.appendChild(newHeadline);

            Object.keys(value[1]).forEach((key, index) => {
                name = key;
                content = value[1][key].time;
                var newContent = createNewContent(key + ": ", content, cloneTitle);
                newContent.style.marginTop = '0px';
                liEl.appendChild(newContent);
            })

        } else {

            var total = createNewContent(headline[i++], value[1].time, cloneTitle);
            liEl.appendChild(total);
        }
    })

    //remove existing data
    liEl.removeChild(liEl.childNodes[1]);
    liEl.removeChild(liEl.childNodes[2]);
    liEl.removeChild(liEl.childNodes[3]);

    return cloneTitle;
}

function createNewContent(headline, text, clone) {

    var content = clone.childNodes[1].childNodes[1].childNodes[1].childNodes[1];
    var headline = content.getElementsByTagName("dt")[0].innerHTML = headline;
    content.getElementsByTagName("dd")[0].innerText = text;
    clone.getElementsByTagName("dd")[0].title = text;

    return content.cloneNode(true);
}


function getTimeSummary() {

    var list = this.list;

    fetch(this.url)
        .then(function (response) {
            if (!response.ok) {
                throw new Error(response.statusText);
            }
            return response.json();
        })
        .then(data => {

            if (list.lastChild.textContent == 'Es liegen keine Informationen vor.') {

                list.removeChild(list.lastChild);
            }

            if (this.labJira == false) {

                var newDiv = createTimeSummary(list, data);

            } else {
                var newDiv = createLabJiraTimeSummay(list, data);
            }
            list.appendChild(newDiv);
            this.button.style.display = 'none';
        })
        .catch(e => {

            if (list.lastChild.textContent != 'Es liegen keine Informationen vor.') {
                getNewDiv('Es liegen keine Informationen vor.', list);
            }
            console.log("Request failed: ", e);
        }
    );
}
