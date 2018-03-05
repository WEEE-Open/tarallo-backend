(function() {
	"use strict";

	document.execCommand('defaultParagraphSeparator', false, 'div');

	/** @type {Set<string>} */
	let deletedFeatures = new Set();

	let divs = document.querySelectorAll('.features.own.editing [contenteditable]');
	let selects = document.querySelectorAll('.features.own.editing select');
	let deletes = document.querySelectorAll('.features.own.editing .delete');
	// noinspection JSUnresolvedFunction It's perfectly resolved, it's there, it exists
	document.querySelector('.item.editing .add button').addEventListener('click', addFeature);
	// noinspection JSUnresolvedFunction
	document.querySelector('.item.editing .itembuttons .save').addEventListener('click', saveClick);
	// noinspection JSUnresolvedFunction
	document.querySelector('.item.editing .itembuttons .cancel').addEventListener('click', goBack);

	for(let div of divs) {
		if(div.dataset.internalType === 's') {
			div.addEventListener('input', textChanged);
		} else {
			div.addEventListener('blur', numberChanged);
		}
	}

	for(let select of selects) {
		select.addEventListener('change', selectChanged);
	}

	for(let deleteButton of deletes) {
		let bound = deleteItem.bind(null, deletedFeatures);
		deleteButton.addEventListener('click', bound);
	}

	/**
	 * Remove last error message (or any element, really)
	 *
	 * @param {HTMLElement|null} element to be removed, or null to remove last error message
	 */
	function removeError(element = null) {
		if(element === null) {
			let last = document.getElementById('feature-edit-last-error');
			if(last !== null) {
				last.parentElement.removeChild(last);
			}
		} else {
			element.parentElement.removeChild(element);
		}
	}

	/**
	 * Handle changing content of an editable text div
	 *
	 * @param ev Event
	 * @TODO: adding and removing newlines should count as "changed", but it's absurdly difficult to detect, apparently...
	 */
	function textChanged(ev) {
		fixDiv(ev.target);
		// Newly added element
		if(!ev.target.dataset.initialValue) {
			return;
		}

		if(ev.target.textContent.length === ev.target.dataset.initialValue.length) {
			if(ev.target.textContent === ev.target.dataset.initialValue) {
				ev.target.classList.remove('changed');
				return;
			}
		}
		ev.target.classList.add('changed');
	}

	/**
	 * Handle changing value of a <select>
	 *
	 * @param ev Event
	 */
	function selectChanged(ev) {
		// New elements don't have an initial value
		if(!ev.target.dataset.initialValue) {
			return;
		}
		if(ev.target.value === ev.target.dataset.initialValue) {
			ev.target.classList.remove('changed');
		} else {
			ev.target.classList.add('changed');
		}
	}

	/**
	 * Handle changing content of an editable div containing numbers
	 *
	 * @param ev Event
	 */
	function numberChanged(ev) {
		fixDiv(ev.target);
		let value = ev.target.textContent;
		let unit;
		if(ev.target.dataset.unit) {
			unit = ev.target.dataset.unit;
		} else {
			// Extreme caching techniques
			unit = nameToType(ev.target.dataset.internalName);
			ev.target.dataset.unit = unit;
		}
		try {
			let newValue = printableToValue(unit, value);
			if(ev.target.dataset.internalType === 'i' && (newValue % 1 !== 0)) {
				// noinspection ExceptionCaughtLocallyJS
				throw new Error("fractional-not-allowed");
			}
			// Store new value
			ev.target.dataset.internalValue = newValue.toString();
			// Print it
			let lines = ev.target.getElementsByTagName('DIV');
			lines[0].textContent = valueToPrintable(unit, newValue);
			while(lines.length > 1) {
				let last = lines[lines.length - 1];
				last.parentElement.removeChild(last);
			}
			// Save if for later
			ev.target.dataset.previousValue = newValue.toString();
		} catch(e) {
			// rollback
			ev.target.dataset.internalValue = ev.target.dataset.previousValue;
			ev.target.getElementsByTagName('DIV')[0].textContent = valueToPrintable(unit, parseInt(ev.target.dataset.previousValue));
			// Display error message
			removeError(null);
			let displayed = displayError(e.message);
			if(!displayed) {
				throw e;
			}
		}
		// New elements don't have an initial value
		if(!ev.target.dataset.initialValue) {
			return;
		}
		if(ev.target.dataset.internalValue === ev.target.dataset.initialValue) {
			ev.target.classList.remove('changed');
		} else {
			ev.target.classList.add('changed');
		}
	}

	/**
	 * Show error messages.
	 *
	 * @param {string|null} templateName
	 * @param {string|null} message
	 */
	function displayError(templateName = null, message = null) {
		let templateThingThatShouldExist;
		if(templateName === null) {
			templateThingThatShouldExist = document.getElementById('feature-edit-template-generic-error');
		} else {
			templateThingThatShouldExist = document.getElementById('feature-edit-template-' + templateName);
			if(templateThingThatShouldExist === null) {
				// Unhandled exception!
				return false;
			}
		}
		let template = document.importNode(templateThingThatShouldExist.content, true);

		let item = document.querySelector('.item.editing');
		item.insertBefore(template, item.getElementsByTagName('HEADER')[0].nextElementSibling);
		// "template" is a document fragment, there's no way to get the element itself
		let inserted = document.querySelector('.item.editing .error.message');
		inserted.id = 'feature-edit-last-error';
		inserted.getElementsByTagName('BUTTON')[0].addEventListener('click', removeError.bind(null, inserted));
		if(message !== null) {
			inserted.firstChild.textContent = message;
		}
	}

	/**
	 * Get the correct representation of a unit, from the internal (untranslated) feature name
	 *
	 * @param {string} name "frequency-hertz" et al
	 * @return {string} "Hz" et al
	 */
	function nameToType(name) {
		let pieces = name.split('-');
		switch(pieces[pieces.length - 1]) {
			case 'byte':
				return 'byte';
			case 'hertz':
				return 'Hz';
			case 'decibyte':
				return 'B';
			case 'ampere':
				return 'A';
			case 'volt':
				return 'V';
			case 'watt':
				return 'W';
			case 'inch':
				return 'in.';
			case 'gram':
				return 'g';
			default: // mm, rpm, n, byte (they're all handled separately)
				return pieces[pieces.length - 1];
		}
	}

	/**
	 * Parse the unit prefix and return exponent (or 0 if it isn't a prefix)
	 *
	 * @param {string} char - lowercase character
	 * @returns {number} exponent
	 */
	function prefixToExponent(char) {
		switch(char) {
			case 'k':
				return 1;
			case 'm':
				return 2;
			case 'g':
				return 3;
			case 't':
				return 4;
			case 'p':
				return 5;
			case 'e':
				return 6;
			//case 'µ':
			//case 'u':
			//	return -2;
			//case 'n':
			//	return -3;
			default:
				return 0;
		}
	}

	/**
	 * Convert that number into something printable
	 *
	 * @param {string} unit - byte, Hz, V, W, etc...
	 * @param {int} value
	 * @returns {string}
	 */
	function valueToPrintable(unit, value) {
		let prefix = 0;
		switch(unit) {
			case 'n':
				return value.toString();
			case 'rpm':
			case 'mm':
			case 'in.':
				return value.toString() + ' ' + unit;
			case 'byte':
				while(value >= 1024 && prefix <= 6) {
					value /= 1024; // this SHOULD already be optimized internally to use bit shift
					prefix++;
				}
				let i = '';
				if(prefix > 0) {
					i = 'i';
				}
				return '' + value + ' ' + prefixToPrintable(prefix, true) + i +'B';
			default:
				return appendUnit(value, unit);
		}
	}

	/**
	 * Reduce a number to 3 digits (+ decimals) and add a unit to it
	 *
	 * @param {int} value - numeric value of the base unit (e.g. if base unit is -1, unit is "W", value is 1500, then result is "1.5 W")
	 * @param {string} unit - unit symbol, will be added to the prefix
	 * @param {int} [baseUnit] - base unit multiplier (e.g. 0 for volts, -1 for millivolts, 1 of kilovolts)
	 * @return {string} "3.2 MHz" and the like
	 */
	function appendUnit(value, unit, baseUnit = 0) {
		let prefix = baseUnit;
		while(value >= 1000 && prefix <= 6) {
			value /= 1000;
			prefix++;
		}
		return '' + value + ' ' + prefixToPrintable(prefix) + unit;
	}

	/**
	 * Get unit prefix in string format. 0 is none.
	 *
	 * @param {int} int - 1 for k, 2 for M, etc...
	 * @param {boolean} bigK - Use K instead of the standard k. Used for bytes, for some reason.
	 * @return {string}
	 */
	function prefixToPrintable(int, bigK = false) {
		switch(int) {
			case 0:
				return '';
			case 1:
				if(bigK) {
					return 'K';
				} else {
					return 'k';
				}
			case 2:
				return 'M';
			case 3:
				return 'G';
			case 4:
				return 'T';
			case 5:
				return 'P';
			case 6:
				return 'E';
			case -1:
				return 'm';
			//case -2:
			//	return 'µ';
			//case -3:
			//	return 'n';
		}
		throw new Error('invalid-prefix');
	}

	/**
	 * Parse input (from HTML) and convert to internal value.
	 *
	 * @param {string} unit
	 * @param {string} input - a non-empty string
	 * @throws Error if input is in wrong format
	 * @return {number}
	 * @private
	 */
	function printableToValue(unit, input) {
		/** @type {string} */
		let string = input.trim();
		if(string === "") {
			throw new Error("empty-input")
		} else if(unit === 'n') {
			let number = parseInt(input);
			if(isNaN(number) || number < 0) {
				throw new Error("negative-input")
			} else {
				return number;
			}
		}
		let i;
		for(i = 0; i < string.length; i++) {
			if (!((string[i] >= '0' && string[i] <= '9') || string[i] === '.' || string[i] === ',')) {
				break;
			}
		}
		if(i === 0) {
			throw new Error('string-start-nan');
		}
		let number = parseFloat(string.substr(0, 0 + i));
		if(isNaN(number)) {
			throw new Error('string-parse-nan')
		}
		let exp = 0;
		if(unit === 'mm') {
			// everything breaks down because:
			// - base unit ("m") contains an M
			// - "m" and "M" are acceptable prefixes (M could be ignored, but still "m" and "m" and "mm" are ambiguous)
			// so...
			exp = 0;
			// TODO: match exactly "m", "Mm" and "mm", coerce "mM" and "MM" into something sensibile, if we need this. Also, shouldn't this be a double?
		} else {
			for(; i < string.length; i++) {
				let lower = string[i].toLowerCase();
				if(lower >= 'a' && lower <= 'z') {
					exp = prefixToExponent(lower);
					break;
				}
			}
		}
		let base;
		if(unit === 'byte') {
			base = 1024;
		} else {
			base = 1000;
		}
		return number * (base ** exp);
	}

	/**
	 * Handle clicking the "X" button
	 *
	 * @param {Set} set Deleted features
	 * @param ev Event
	 */
	function deleteItem(set, ev) {
		set.add(ev.target.dataset.name);
		ev.target.parentElement.parentElement.parentElement.removeChild(ev.target.parentElement.parentElement);
	}

	let featureSelector = document.querySelector('.item.editing .add select');

	/**
	 * Maybe a template would have been better...
	 */
	function addFeature() {
		let name = featureSelector.value;
		let translatedName = featureSelector.options[featureSelector.selectedIndex].textContent;
		let type = featureTypes.get(name);
		let id = 'feature-edit-' + name;

		let element = document.getElementById(id);
		if(element !== null) {
			element.focus();
			return;
		}
		let newElement = document.createElement("li");
		let elementName = document.createElement("div");
		elementName.classList.add("name");
		newElement.appendChild(elementName);
		let elementLabel = document.createElement("label");
		elementLabel.htmlFor = id;
		elementLabel.textContent = translatedName;
		elementName.appendChild(elementLabel);

		let valueElement, div;
		switch(type) {
			case 'e':
				valueElement = document.createElement('select');
				let options = featureValues.get(name);
				let optionsTranslated = featureValuesTranslated.get(name);
				let optionsArray = [];
				for(let i = 0; i < options.length; i++) {
					let option = document.createElement('option');
					option.value = options[i];
					option.textContent = optionsTranslated[i];
					optionsArray.push(option);
				}
				optionsArray.sort((a, b) => a.textContent.localeCompare(b.textContent, 'en'));
				for(let option of optionsArray) {
					valueElement.appendChild(option);
				}
				break;
			case 'i':
			case 'd':
				valueElement = document.createElement('div');
				valueElement.dataset.internalValue = '0';
				valueElement.dataset.previousValue = '0';
				valueElement.contentEditable = 'true';
				valueElement.addEventListener('blur', numberChanged);

				div = document.createElement('div');
				div.textContent = '0';
				valueElement.appendChild(div);
				break;
			default:
				valueElement = document.createElement('div');
				valueElement.dataset.internalValue = ''; // Actually unused
				valueElement.dataset.previousValue = '';
				valueElement.contentEditable = 'true';
				valueElement.addEventListener('input', textChanged);

				div = document.createElement('div');
				div.textContent = '?'; // empty <div>s break everything
				valueElement.appendChild(div);
				break;
		}

		valueElement.dataset.internalType = type;
		valueElement.dataset.internalName = name;
		valueElement.classList.add("value");
		valueElement.id = id;
		newElement.appendChild(valueElement);

		let controlsElement = document.createElement('div');
		controlsElement.classList.add('controls');
		newElement.appendChild(controlsElement);

		let deleteButton = document.createElement('button');
		deleteButton.classList.add('delete');
		deleteButton.dataset.name = name;
		deleteButton.textContent = '❌';
		deleteButton.addEventListener('click', deleteItem.bind(null, deletedFeatures));
		controlsElement.appendChild(deleteButton);

		// Undelete
		deletedFeatures.delete(name);
		// Insert
		document.querySelector('.item.editing .features.own.editing .new ul').appendChild(newElement);
	}

	async function saveClick() {
		let counter = 0;
		let delta = {};

		let changed = document.querySelectorAll('.item.editing .features.own.editing .value.changed, .item.editing .features.own.editing .new .value');

		for(let element of changed) {
			switch(element.dataset.internalType) {
				case 'e':
					delta[element.dataset.internalName] = element.value;
					break;
				case 'i':
				case 'd':
					delta[element.dataset.internalName] = element.dataset.internalValue;
					break;
				case 's':
				default:
					let paragraphs = element.getElementsByTagName('DIV');
					let lines = [];
					for(let paragraph of paragraphs) {
						lines.push(paragraph.textContent);
					}
					delta[element.dataset.internalName] = lines.join('\n');
			}
			counter++;
		}

		for(let deleted of deletedFeatures) {
			delta[deleted] = null;
			counter++;
		}

		if(counter <= 0) {
			return;
		}

		let id = document.querySelector('.item.editing').dataset.code;

		for(let button of document.querySelectorAll('.itembuttons button')) {
			button.disabled = true;
		}

		let response = await fetch('/v1/items/' + encodeURIComponent(id) + '/features', {
			headers: {
				'Accept': 'application/json',
				'Content-Type': 'application/json'
			},
			method: 'PATCH',
			credentials: 'include',
			body: JSON.stringify(delta)
		});

		await jsendMe(response, goBack, )
	}

	async function jsendMe(response, onsuccess) {
		try {
			if(response.headers.get("content-type").indexOf("application/json") > -1) {
				try {
					let jsend = await response.json();
					if(response.ok && jsend.status === 'success') {
						onsuccess();
					} else {
						if(jsend.status === 'fail') {
							if(jsend.data) {
								for(let field of Object.keys(jsend.data)) {
									let message = jsend.data[field];
									displayError(null, message);
									let input = document.getElementById('feature-edit-' + field);
									if(input !== null) {
										input.classList.add('invalid');
									}
								}
							} else {
								// "fail" with no data
								displayError(null, response.status.toString() + ': unspecified validation error');
							}
						} else {
							// JSend error, or not a JSend response
							displayError(null, response.status.toString() + ': ' + jsend.message ? jsend.message : '');
						}
					}
				} catch(e) {
					// invalid JSON
					displayError(null, e.message);
					console.error(response.body);
				}
			} else {
				// not JSON
				let text = await response.text();
				displayError(null, response.status.toString() + ': ' + text);
			}
		} finally {
			for(let button of document.querySelectorAll('.itembuttons button')) {
				button.disabled = false;
			}
		}
	}

	function goBack() {
		let here = window.location.pathname;
		let query = window.location.search;
		let hash = window.location.hash;

		let pieces = here.split('/');
		let penultimate = pieces[pieces.length - 2];
		if(penultimate === 'edit' || penultimate === 'add') {
			pieces.splice(pieces.length - 2);
			window.location.href = pieces.join('/') + query + hash;
		} else {
			// This feels sooooo 2001
			window.history.back();
		}
	}

	/**
	 * Add divs that disappear randomly from contentEditable elements
	 *
	 * @param {HTMLElement} element
	 */
	function fixDiv(element) {
		for(let node of element.childNodes) {
			if(node.nodeType === 3) {
				let div = document.createElement('div');
				div.textContent = node.textContent;
				element.insertBefore(div, node);
				element.removeChild(node);

				// Dima Viditch is the only person in the universe that has figured this out: https://stackoverflow.com/a/16863913
				// Nothing else worked. NOTHING.
				let wrongSelection = window.getSelection();
				let pointlessRange = document.createRange();
				div.innerHTML = '\u00a0';
				pointlessRange.selectNodeContents(div);
				wrongSelection.removeAllRanges();
				wrongSelection.addRange(pointlessRange);
				document.execCommand('delete', false, null);
			}
		}
	}
}());