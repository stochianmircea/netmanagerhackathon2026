# Energy-Aware Network Agent

Meta Global AI Developer Hackathon 2026 · Startup Track · **Push the Frontier**

An agent that reads a real solar installation, compiles a policy **written in plain English**
into concrete network actions, and refuses on its own to touch what must stay alive.

---

## What is new — built during the Hackathon (Oct 27 – Nov 4, 2026)

> Everything listed here was written during the building period.
> The commit history starts on Oct 27 and matches this list.

- [x] `schema/plan.schema.json` — the strict shape every plan must have
- [x] `src/validator.php` — the brake: the never-touch list is enforced **in code**,
      not asked for in the prompt. A plan naming a protected target is rejected whole.
- [x] `src/collector.php` — reads the live inverter state
- [ ] `src/compiler.php` — policy in English + state → an argued plan, via an
      **open-weight model running on our own hardware**
- [ ] `src/executor.php` — applies the approved plan through the RouterOS API
- [ ] `src/journal.php` — what was proposed, what was approved, what ran
- [ ] `policy/example-policy.md` — the English policy the model compiles
- [x] `fixtures/sample-state.json` — anonymised state, so judges can run this
      without our hardware
- [ ] `docs/demo-script.md` — the 3-minute demo, shot on real hardware

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
