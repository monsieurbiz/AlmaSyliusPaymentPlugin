'use strict';

// Receives rendered inline JavaScript and real PlansComponent events from PHPUnit.
// No Alma asset is loaded and the VM has no network or module-loading API.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const fixture = JSON.parse(fs.readFileSync(0, 'utf8'));
const listeners = new Map();
const host = { id: 'alma-widget', rendered: null };
const calls = [];
let initializations = 0;
let unmounts = 0;
const LIVE = Symbol('LIVE');
const PaymentPlans = Symbol('PaymentPlans');

const document = {
    addEventListener(name, listener) {
        assert.equal(name, 'alma:plans:mounted');
        assert.equal(listeners.has(name), false, 'Only one listener is registered');
        listeners.set(name, listener);
    },
    querySelector(selector) {
        assert.equal(selector, '#alma-widget');
        return host;
    },
    getElementById(id) {
        assert.equal(id, 'alma-widget');
        return host;
    },
    dispatchEvent(event) {
        const listener = listeners.get(event.type);
        if (listener) listener(event);
    },
};

const Alma = {
    ApiMode: { LIVE },
    Widgets: {
        PaymentPlans,
        initialize(merchantId, mode) {
            initializations++;
            assert.equal(merchantId, fixture.merchantId, 'Merchant survives JavaScript escaping');
            assert.equal(mode, LIVE, 'Preserve the existing mode without making an API request');
            return {
                add(widget, options) {
                    assert.equal(widget, PaymentPlans);
                    const container = document.querySelector(options.container);
                    assert.equal(container, host, 'Repeated adds must target the same host');
                    // Model V4: each add removes the previous widget and mounts a fresh one.
                    if (container.rendered !== null) unmounts++;
                    container.rendered = { ...options };
                    calls.push(options);
                },
            };
        },
    },
};

vm.runInNewContext(fixture.script, { Alma, document }, { timeout: 1000 });
assert.equal(initializations, 1);
assert.equal(calls.length, 0, 'Wait for a component event before mounting');
document.dispatchEvent({ type: 'unrelated:event', detail: {} });
assert.equal(calls.length, 0);

for (const scenario of fixture.scenarios) {
    const before = calls.length;
    document.dispatchEvent({ type: scenario.event.event, detail: scenario.event.payload });
    assert.equal(calls.length, before + 1, scenario.name);
    const options = calls.at(-1);
    assert.equal(options.container, '#alma-widget');
    assert.equal(options.purchaseAmount, scenario.amount, `${scenario.name}: amount stays in cents`);
    assert.equal(Number.isInteger(options.purchaseAmount), true);
    assert.equal(options.locale, scenario.locale, `${scenario.name}: supported widget locale`);
    assert.equal(options.plans, scenario.event.payload.plans, 'Plans are forwarded without replacement');
    assert.deepEqual(options.plans, scenario.plans);
    assert.deepEqual(host.rendered, { ...options }, 'The remounted widget displays the newest payload');
}

assert.equal(unmounts, fixture.scenarios.length - 1);
console.log(`OK: ${calls.length} rendered events, ${unmounts} offline remounts`);
