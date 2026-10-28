# Energy-Aware Network Agent

Meta Global AI Developer Hackathon 2026 · Startup Track · **Push the Frontier**

An agent that reads a real solar installation, compiles a policy **written in plain English**
into concrete network actions, and refuses on its own to touch what must stay alive.

The policy is compiled by **Llama 3.3, running on our own hardware** through Ollama — no cloud
call, no site data leaving the building. The model proposes and argues; code decides what may
be touched at all.

---

## What is new — built during the Hackathon (Oct 27 – Nov 4, 2026)

> Everything listed here was written during the building period.
> The commit history starts on Oct 27 and matches this list.

- [x] `schema/plan.schema.json` — the strict shape every plan must have
- [x] `src/validator.php` — the brake: the never-touch list is enforced **in code**,
      not asked for in the prompt. A plan naming a protected target is rejected whole.
- [x] `src/collector.php` — reads the live inverter state
- [x] `src/compiler.php` — policy in English + state → an argued plan, via
      **Llama 3.3 running on our own hardware** (Ollama), with one code-driven
      correction round: the validator's rejections go back to the model exactly once,
      and a second failure is a refusal, not a third attempt
- [x] `src/executor.php` — applies the approved plan through the RouterOS API,
      bound to the fingerprint of the plan that was approved
- [x] `src/journal.php` — what was proposed, what was approved, what ran —
      and every action separately, including the ones that failed
- [x] `policy/example-policy.md` — the English policy the model compiles
- [x] `fixtures/sample-state.json` — anonymised state, so judges can run this
      without our hardware
- [x] `docs/demo-script.md` — the shot list for the 3-minute demo, forced on real
      hardware (the video link goes in below, before submission)

## What already existed — NOT part of the Hackathon

NetManager is our existing commercial product, in production since 2024. It is **not**
submitted as hackathon work and its code is **not** in this repository:

- network discovery, device authorisation and internet provisioning via the RouterOS API
- NIS2 audit scanner
- the ARIA assistant, its memory, messaging bridge and voice layer
- camera module, gate control, energy collection

The agent in this repository talks to that platform through its existing interfaces.
None of the platform's source code is included here.

---

## How it works

```
inverter state ─┐
policy (English)├─→ compiler (open-weight model) ─→ plan (JSON, argued)
device inventory┘                                        │
                                                         ▼
                                                 validator (code)
                                              never-touch enforced
                                                         │
                                      rejected ←─────────┴─────────→ approved
                                                                         │
                                                                         ▼
                                                             executor (RouterOS)
                                                                         │
                                                                         ▼
                                                                     journal
```

The model does the one thing code cannot: read a policy a human wrote in their own words
and weigh two things at once — *"cut first what consumes a lot and matters little"* — then
explain its reasoning. The code does the one thing a model must never be trusted with:
deciding what is allowed to be touched.

## Running it without our hardware

```
cp .env.example .env
php src/collector.php --fixture fixtures/sample-state.json
```

## Demo video

_(link added before submission)_

## License

MIT — see [LICENSE](LICENSE).


## Day 5 — the executor, and the journal

Everything before this point produces words. `src/executor.php` is where a word becomes a switch
going off in a building, so it is deliberately the hardest file here to persuade.

**The approval is bound to the plan, not to the moment.** `plan_fingerprint()` hashes what will be
*done* — target, operation, argument, claimed saving, and the state the plan was argued on. It
deliberately excludes the prose: rewording an explanation must not invalidate an approval, and
changing an action must. So a human who approves one plan has not approved a different one, and
editing an action after approval leaves the approval pointing at a plan that no longer exists.

**A state goes cold.** A plan argued on a reading from twenty minutes ago is not a plan about now —
the battery has moved. The executor refuses a state older than `EXECUTOR_MAX_STATE_AGE` (300 s)
even with a perfectly valid approval, and says so by name: `STALE`.

**It re-validates. It does not trust that someone validated it earlier.** The never-touch list and
the arithmetic are checked again here, on the state in hand, because the only thing an attacker or
a bug has to defeat is the last gate, not the first one.

**The agent's reach is exactly what the owner wrote down.** There is no generic "do something to a
device" path in this file. An operation is performable only if the site file declares an `apply`
block for it — the exact request to send:

```json
"disable": { "saves_w": 120,
             "apply": { "method": "POST",
                        "url": "https://10.0.0.1/rest/interface/wireless/set",
                        "body": { "numbers": "{{target}}", "disabled": "yes" },
                        "user_env": "ROUTEROS_USER", "pass_env": "ROUTEROS_PASSWORD" } }
```

Only `{{argument}}` and `{{target}}` are interpolated. No expressions, no fact lookups, no second
placeholder syntax. Credentials are never written in the file: `user_env` and `pass_env` name
environment variables. An operation with a measured worth and no `apply` — `media-server`'s
`set_power_profile` in `config/site.example.json` — can be reasoned about and proposed, and cannot
be carried out. The executor refuses it in those words. This means the file contains no knowledge
of anyone's network: every endpoint it knows came out of a file the site owner wrote.

**Nothing happens without `--apply`.** The default prints the request it would send, down to the
body, and the fingerprint needed to authorise it.

`src/journal.php` is append-only NDJSON, one object per line, readable with `tail` and greppable
without a tool: what was proposed, every rejection the validator raised, who approved and when,
and the result of **each action separately** — including the ones that failed. A half-applied plan
is the most important thing a journal ever has to explain, so it is never recorded as one verdict.
`journal_scrub()` redacts anything whose key looks like a credential and any long opaque string,
whatever the caller handed it. The journal stays on the machine that made it; `journal/.gitignore`
keeps it out of this repository.

### Try it without hardware

```sh
php tools/freshen.php fixtures/sample-state.json > /tmp/state.json
php src/executor.php --plan=fixtures/plan-accepted.json --state=/tmp/state.json \
                     --site=config/site.example.json
# DRY RUN — nothing was sent. Plan fingerprint: 855daf45891523a4
#   disable guest-wifi    would send: POST https://10.0.0.1/rest/interface/wireless/set
#   disable lab-switch    would send: POST https://10.0.0.1/rest/interface/ethernet/poe/set

php src/executor.php --plan=fixtures/plan-accepted.json --state=fixtures/sample-state.json \
                     --site=config/site.example.json
# REFUSED — nothing was sent
#   STALE: the state is 9858 seconds old, the limit is 300 — read the installation again and re-plan

php src/journal.php --last=10
```

`tools/freshen.php` re-stamps the frozen fixture's `taken_at` and nothing else. It exists because
the staleness rule must not have an exception: the honest way to demonstrate a dated fixture is a
visible tool that changes the timestamp, not a flag inside the gate that switches the rule off.

### Tests

```sh
php tests/run_all.php
```

Four suites, no hardware: the validator (28), the collector (15), the compiler (18) and the
executor (22). The executor suite replaces the request sender with one that records what it was
handed, so the cases that must send nothing can prove they sent nothing — approving one plan and
running another, editing an action after approval, applying against a cold state, an operation
with no declared request, a target the owner left out of the site file, and a plan that fails
halfway and is journalled action by action.
