// ==UserScript==
// @name     Timetracker times in JIRA Cloud
// @version  2
// @description Display tracked times on the JIRA Cloud ticket details page
// @include /https:\/\/.*\.atlassian\.net\/browse\/.*/
// @icon     https://timetracker/favicon.ico
// @grant    none
// ==/UserScript==


const ticket = globalThis.location.href.split('/').slice(-1)[0];
const timetrackerUrl = 'https://timetracker/getTicketTimeSummary/' + ticket;

window.addEventListener('load', function () {

    let list = document.getElementById('viewissuesidebar');
    let labJira = true;

    if (list === null) {
        labJira = false;
        list = document.querySelector(
            "[data-test-id='issue.views.issue-base.context.context-items.primary-items']"
        );
    }

    createButton(labJira, list);

}, false);

function createButton(labJira, list) {

    const button = document.createElement('button');
    button.innerText = 'Zeiten aus Timetracker laden';
    button.style.marginBottom = '35px';
    button.style.background = "rgb(0, 82, 204)";
    button.style.color = "rgb(255, 255, 255)";
    button.style.height = '30px';

    if (labJira) {
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
    const divElement = document.createElement('div');

    if (list) {
        divElement.style.paddingLeft = '10px';
    }

    divElement.style.marginTop = '5px';
    const nodeContent = document.createTextNode(content);
    divElement.appendChild(nodeContent);
    div.appendChild(divElement);
}

function createTimeSummary(list, data) {

    const headline = ['Gesamtzeit:\xa0\xa0', 'T\u00e4tigkeiten:', 'Personen:'];
    const title = document.querySelector(
        "[data-test-id='issue.views.issue-base.context.context-items.primary-items']"
    ).childNodes[0].childNodes[0].childNodes[0];
    const cloneTitle = title.cloneNode(true);
    cloneTitle.childNodes[0].textContent = 'Aufgewendete Zeit';

    const divTitle = document.createElement('div');
    divTitle.appendChild(cloneTitle);
    list.appendChild(divTitle);

    const newDiv = document.createElement('div');
    newDiv.style.marginBottom = '20px';
    newDiv.style.display = 'flex';
    newDiv.style.flexDirection = 'row';
    newDiv.style.flexWrap = 'wrap';
    newDiv.style.width = '100%';

    const rowOne = document.createElement('div');
    rowOne.className = 'one';
    rowOne.style.display = 'flex';
    rowOne.style.flexDirection = 'column';
    rowOne.style.flexBasis = '100%';
    rowOne.style.flex = 'inherit';

    const rowtwo = rowOne.cloneNode(true);
    rowtwo.className = 'two';

    let i = 0;
    Object.entries(data).forEach((value) => {
        const time = value[1].time;

        if (time) {
            getNewDiv(headline[i++], rowOne);
            getNewDiv(value[1].time, rowtwo, true);
        } else {
            getNewDiv(headline[i++], rowOne);
            getNewDiv('\xa0', rowtwo, true);

            Object.keys(value[1]).forEach((key) => {
                const content = value[1][key].time;
                getNewDiv(key + ": ", rowOne, true);
                getNewDiv(content, rowtwo, true);
            })
        }
    })
    newDiv.appendChild(rowOne);
    newDiv.appendChild(rowtwo);
    return newDiv;
}

function createLabJiraTimeSummay(list, data) {
    const headline = ["<b>Gesamtzeit:\xa0\xa0</b>", "<b>T\u00e4tigkeiten:</b>", "<b>Personen:</b>"];

    const title = document.getElementById('datesmodule');
    const cloneTitle = title.cloneNode(true);
    cloneTitle.childNodes[0].childNodes[1].textContent = 'Aufgewendete Zeit';
    let i = 0;

    const liEl = cloneTitle.childNodes[1].childNodes[1].childNodes[1];

    Object.entries(data).forEach((value) => {
        const time = value[1].time;

        if (time) {

            const total = createNewContent(headline[i++], value[1].time, cloneTitle);
            liEl.appendChild(total);
        } else {

            const newHeadline = createNewContent(headline[i++], '\xa0', cloneTitle);
            liEl.appendChild(newHeadline);

            Object.keys(value[1]).forEach((key) => {
                const content = value[1][key].time;
                const newContent = createNewContent(key + ": ", content, cloneTitle);
                newContent.style.marginTop = '0px';
                liEl.appendChild(newContent);
            })

        }
    })

    // remove the template's own entries (the first three element children;
    // the freshly appended ones come after them)
    for (const node of [...liEl.children].slice(0, 3)) {
        node.remove();
    }

    return cloneTitle;
}

function createNewContent(headline, text, clone) {

    const content = clone.childNodes[1].childNodes[1].childNodes[1].childNodes[1];
    content.getElementsByTagName("dt")[0].innerHTML = headline;
    content.getElementsByTagName("dd")[0].innerText = text;
    clone.getElementsByTagName("dd")[0].title = text;

    return content.cloneNode(true);
}


function getTimeSummary() {

    const list = this.list;

    fetch(this.url)
        .then(function (response) {
            if (!response.ok) {
                throw new Error(response.statusText);
            }
            return response.json();
        })
        .then(data => {

            if (list.lastChild.textContent == 'Es liegen keine Informationen vor.') {

                list.lastChild.remove();
            }

            const newDiv = this.labJira
                ? createLabJiraTimeSummay(list, data)
                : createTimeSummary(list, data);
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
