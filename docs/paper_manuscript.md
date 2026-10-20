# Cascaded Hybrid Moderation for Detecting Off-Platform Transaction Circumvention in Thai C2C Escrow Chat

**Manuscript draft (IEEE/ACM-style)**  
**Evidence source:** live CLI benchmark on `dataset/benchmark_dataset.json` (`N=333`), summarized in `dataset/benchmark_runs/summary_live.json` and `dataset/benchmark_runs/paper_tables_live.tex`.  
**Evaluation mode:** live multi-provider LLM cascade (`MOCK_API_MODE=false`; Groq primary, OpenRouter fallback).

---

## Abstract

Consumer-to-consumer (C2C) escrow platforms fail when buyers and sellers coordinate payments outside the platform—sharing bank accounts, PromptPay identifiers, phone numbers, or Line contacts—thereby defeating escrow protection. Applying a cloud large language model (LLM) to every chat message is accurate in principle but costly and latency-heavy; rule-only detectors are cheap but miss evasive Thai phrasing. We present a **three-tier cascaded hybrid moderation architecture** for Thai escrow chat: (i) local regex/heuristic screening, (ii) on-demand multi-provider LLM escalation (Groq → OpenRouter → Gemini), and (iii) graceful degradation when cloud inference fails. We evaluate the system on a Thai fraud-chat corpus of **\(N=333\)** utterances extracted via OCR from **100** confirmed-fraud screenshots from public Thai anti-scam pages (**270 ALLOW**, **63 BLOCK**), labeled by **100% human adjudication**. In a live production-path benchmark, the cascaded hybrid reaches **91.0%** accuracy (precision **0.824**, recall **0.667**, F1 **0.737**, \(\kappa=0.68\)) while terminating at Tier~1 for **79.3%** of messages, yielding **79.3%** API-call savings and a **56.7% projected** LLM-token cost reduction versus an LLM-on-every-message counterfactual, at a mean latency of **419.8~ms** (versus **2025.6~ms** for the counterfactual). A McNemar test against regex-only moderation finds no statistically significant accuracy difference at \(\alpha=0.05\) (\(p=0.1489\)). We therefore **do not claim an accuracy gain**; the cascade is motivated by production constraints (latency, token cost, availability) under comparable detection quality.

**Index Terms**—C2C escrow, chat moderation, cascaded inference, Thai NLP, fraud detection, cost-aware AI systems.

---

## I. Introduction

### A. Motivation

Escrow-mediated C2C marketplaces hold buyer funds until delivery conditions are met. This safety property collapses when counterparties move the conversation *off-platform*: requesting direct bank transfer, PromptPay top-up, or Line/phone contact. In Thai peer marketplaces—especially Facebook Marketplace and Messenger-mediated deals—such circumvention is common, often culminating in non-delivery after a successful off-platform transfer.

A deployed escrow product therefore needs **in-chat detection** of off-platform payment and contact bait, with constraints that matter in production: low false-alarm rate (so legitimate bargaining is not blocked), low latency (chat UX), and bounded cloud cost.

### B. Empirical Insight from Real Fraud Screenshots

We collected **100** real Thai C2C fraud chat screenshots from public warning communities and Marketplace-related cases. Optical character recognition (OCR) yielded **\(N=333\)** conversational utterances. Qualitatively and quantitatively, fraud dialogues rarely begin with slangy “scam language.” Instead, approximately **85%** of early turns resemble a **normal trade flow**—price inquiry, condition questions, shipping logistics—while risky intent concentrates in later turns that share account numbers, PromptPay IDs, slips, or Line handles.

This temporal structure motivates cascading: most messages should be decided locally at near-zero cost; only ambiguous mid-risk text should invoke an LLM.

### C. Limitations of Extremes

1. **Regex-only.** Pattern matching on Thai off-platform keywords, phones, and account formats is fast and highly precise when a strong cue is present. However, soft or fragmented bait—and OCR noise—produce **false negatives** (FN), reducing recall.
2. **LLM-always.** Sending every message to a cloud LLM improves contextual coverage but incurs high **latency** and **API cost**, which is wasteful when \(\sim\)80% of turns are routine inquiry.

### D. Contributions

This paper makes three contributions:

1. **Architecture.** A production-oriented **3-tier cascaded hybrid** moderator for Thai escrow chat (local regex → multi-provider LLM → graceful fallback), implemented in a live PHP escrow codebase.
2. **Dataset.** A Thai OCR-derived benchmark of **\(N=333\)** utterances from **100** confirmed-fraud screenshots, labeled **ALLOW/BLOCK** by 100% human adjudication (single annotator), with anonymized identifiers.
3. **Empirical evaluation.** A live head-to-head comparison of regex-only versus cascaded hybrid on the OCR corpus, reporting classification metrics, McNemar significance, latency, and **projected** token cost—**without fabricated statistics**.

---

## II. Proposed Cascaded Architecture

We denote a message \(x\) and a binary risk decision \(y\in\{\mathrm{ALLOW},\mathrm{BLOCK}\}\), where production verdicts `flag`/`reject` map to **BLOCK** and `pass` maps to **ALLOW**.

### A. Tier 1 — Local Regex and Heuristics

Tier~1 normalizes Thai/Latin text and scores cues including:

- Thai mobile / international phone patterns;
- bank-account digit formats and bank-name keywords;
- PromptPay and off-platform transfer phrases (e.g., โอนนอก, ไม่ผ่านแอป);
- Line ID / social contact patterns;
- discount + bypass bait conjunctions.

A risk score \(s_{\mathrm{regex}}\in[0,100]\) is mapped to verdicts with production thresholds (flag \(\geq 45\), block/reject \(\geq 70\)). These cutoffs, together with the escalation bounds below, are **empirical prototype defaults** chosen by engineering intuition; they were **not** tuned on a held-out split of this corpus.

### B. Tier 2 — Cloud LLM Multi-Provider Escalation

Escalation triggers when AI moderation is enabled and, approximately,

\[
s_{\mathrm{regex}}\in[s_{\min}, s_{\mathrm{skip}})
\quad\text{or}\quad
(s_{\mathrm{regex}}=0 \land \text{suspicious long digit run}),
\]

with defaults \(s_{\min}=25\) and \(s_{\mathrm{skip}}=80\).

Providers are tried in order: **Groq → OpenRouter → Gemini**. The live run used free-tier endpoints with production default model IDs: `llama-3.3-70b-versatile` (Groq), `google/gemini-2.5-flash` (OpenRouter), `gemini-2.0-flash` (Gemini). Decoding uses temperature \(0.1\) and JSON-object response format (`max_tokens=2048`). The prompt scores only off-platform circumvention cues and must return JSON with `risk_score` \(\in[0,100]\), `verdict` \(\in\{\texttt{pass},\texttt{flag},\texttt{reject}\}\), `reasons`, and `categories`. Invalid JSON, empty responses, auth errors, and timeouts fall through the provider chain; if all fail, Tier~3 returns the Tier~1 decision.

Results are merged conservatively via \(\max(s_{\mathrm{regex}}, s_{\mathrm{llm}})\) and a worse-verdict operator (`reject` \(\succ\) `flag` \(\succ\) `pass`). This policy is intentionally false-negative-averse.

### C. Tier 3 — Graceful Degradation

If all providers fail (timeout, auth, empty or unparseable JSON), the system returns the Tier~1 decision and optionally raises `pass`→`flag` when a suspicious digit run is present. On this live corpus, Tier~3 count was **0**.

```text
x → Tier1 Regex
      ├─ decisive / no escalate → ALLOW|BLOCK (provider=none)
      └─ escalate → Tier2 LLM (Groq→OpenRouter→Gemini)
                        ├─ success → merge(regex, llm)
                        └─ all fail → Tier3 fallback (regex ± digit heuristic)
```

---

## III. Dataset and Experimental Setup

### A. Corpus Construction

| Step | Description |
|------|-------------|
| Source | 100 screenshots from public Thai anti-scam pages (confirmed fraud cases; Facebook/Messenger UI) |
| OCR | Google Cloud Vision `DOCUMENT_TEXT_DETECTION` (Thai+English hints), live API |
| Segmentation | Line/bubble heuristics + deduplication of fragment utterances |
| Gold labels | **100% human adjudication** (author), utterance-level; independent of production Tier~1 |
| Anonymization | `[LINE_ID]`, `[PHONE_NUM]`, `[BANK_ACCOUNT]`, `[PROMPTPAY]` |

**Final label counts:** **270 ALLOW**, **63 BLOCK**, total **\(N=333\)**. Artifacts: `dataset/benchmark_dataset.json`.

**Gold-label protocol.** Conversation-level fraud is 100% (sources are documented scam cases). Utterance labels follow: **ALLOW** = ordinary inquiry/logistics; **BLOCK** = sharing bank/PromptPay/phone/Line or soliciting off-platform transfer. Labels were **not** generated from the production regex detector, so the regex-only baseline is not scored against its own rules. Because labeling was performed by one annotator, **IAA is not reported**. Residual OCR/UI noise can still distort the *input text*.

### B. Systems Compared

| System | Implementation |
|--------|----------------|
| **Baseline** | `ChatModerationService::scanTextRegexOnly` (Tier~1 only) |
| **Proposed** | `ChatModerationService::scanText` (full cascade, live LLM) |

Binary mapping: `{flag, reject} → BLOCK`, `{pass} → ALLOW`.

### C. Evaluation protocol

This is a production-path evaluation, not a trained classifier. Thresholds (\(s_{\min}=25\), \(s_{\mathrm{skip}}=80\), flag \(\geq 45\), reject \(\geq 70\)) are empirical defaults and were **not** selected on a development split. Metrics are reported on the full \(N=333\) set; held-out threshold calibration is future work.

### D. Metrics

- Detection: Accuracy, Precision, Recall, F1, Cohen’s \(\kappa\), Wilson 95% CI on accuracy  
- Significance: McNemar on paired correctness vs.\ gold; continuity-corrected \(\chi^2=(|b-c|-1)^2/(b+c)\), df\(=1\); this run \((b,c)=(3,9)\), 12 discordant pairs  
- Efficiency: Tier~1 exit rate, mean/P95 latency, **projected** LLM-token USD vs.\ LLM-every-message counterfactual  
- Cost formula (research proxy, not invoices): \(\widehat{T}=\lceil |prompt|/4\rceil+180\) tokens; blended rates Groq \$0.70 / OpenRouter \$0.30 / Gemini \$0.20 per 1M tokens. OCR (Cloud Vision) is a **fixed paid baseline** for corpus construction, not a cascade operating cost. Failed/timeout calls cost \$0 (Tier~3 fallback).

Runner: `scripts/run_chat_benchmark.php --mode=live`.

---

## IV. Results and Discussion

All numbers below are taken from the **live** CLI run recorded in `summary_live.json` / `paper_tables_live.tex` (generated 2026-08-11T14:59:10Z). Provider mix on escalations: Groq \(58\), OpenRouter \(11\); Tier~3 count \(0\).

### A. Classification Performance

**Table I — Detection performance (\(N=333\), live).**

```latex
\begin{table}[t]
\centering
\caption{Off-platform circumvention detection on OCR Thai C2C fraud-chat screenshots (N=333). FLAG/REJECT map to BLOCK.}
\label{tab:ocr-chat-detection}
\begin{tabular}{lcccccc}
\toprule
Method & Acc. (\%) & Prec. & Rec. & F1 & $\kappa$ & 95\% CI (Acc.) \\
\midrule
Regex-only (Tier~1) & 92.8 & 1.000 & 0.619 & 0.765 & 0.72 & [0.895, 0.951] \\
Cascaded Hybrid (proposed) & 91.0 & 0.824 & 0.667 & 0.737 & 0.68 & [0.874, 0.936] \\
\bottomrule
\end{tabular}
\end{table}
```

**Table II — Confusion matrix and McNemar test.**

```latex
\begin{table}[t]
\centering
\caption{Confusion matrix and McNemar test. $b$: hybrid correct when regex wrong; $c$: opposite.}
\label{tab:ocr-chat-mcnemar}
\begin{tabular}{lcccccc}
\toprule
Method & TP & TN & FP & FN & McNemar $(b,c)$ & $p$ \\
\midrule
Regex-only & 39 & 270 & 0 & 24 & -- & -- \\
Cascaded Hybrid & 42 & 261 & 9 & 21 & (3, 9) & $p=0.1489$ \\
\bottomrule
\end{tabular}
\end{table}
```

**Interpretation.**

- Regex-only is **maximally precise** on this corpus (FP\(=0\), Precision\(=1.000\)) but misses many BLOCK cases (FN\(=24\), Recall\(=0.619\)).
- Cascaded hybrid **recovers three** additional true positives (TP \(39\rightarrow 42\), FN \(24\rightarrow 21\)) but introduces **nine** false positives (FP \(0\rightarrow 9\)), lowering precision to \(0.824\) and accuracy from \(92.8\%\) to \(91.0\%\).
- McNemar \((b,c)=(3,9)\) yields \(p=0.1489\) (**not significant** at \(\alpha=0.05\)). We therefore **do not claim** that the cascade significantly outperforms regex-only on accuracy for this OCR gold set.

### B. System Efficiency (Primary Empirical Strength)

**Table III — Latency and estimated cost (live).**

```latex
\begin{table}[t]
\centering
\caption{Efficiency of the cascade on the OCR screenshot dataset (mode=live).}
\label{tab:ocr-chat-efficiency}
\begin{tabular}{lcccc}
\toprule
Method & Mean (ms) & P95 (ms) & Est.\ cost (USD) & Notes \\
\midrule
Regex-only & 0.2 & 0.3 & 0.000 & Tier~1 only \\
Cascaded Hybrid & 419.8 & 1,045.0 & 0.020123 & Tier1=79.3\%; API-call saving=79.3\% \\
LLM every msg (counterfactual) & 2,025.6 & -- & 0.046477 & 0\% saving \\
\bottomrule
\end{tabular}
\end{table}
```

Key efficiency findings:

| Quantity | Value |
|----------|-------|
| Tier~1 exit | **79.3%** (264 / 333) |
| Escalations (Tier~2) | 69 / 333 |
| API-call saving vs.\ LLM-every | **79.3%** |
| Estimated cost (hybrid vs.\ LLM-every) | **\$0.0201** vs.\ **\$0.0465** (**56.7%** saving) |
| Mean latency (hybrid vs.\ LLM-every proxy) | **419.8~ms** vs.\ **2025.6~ms** (\(\approx 4.8\times\) faster on average) |
| Hybrid P95 latency | **1045.0~ms** |

These results align with the empirical \(\sim\)85% normal-trade preamble: most utterances never need cloud inference.

### C. Failure and Trade-off Analysis

**False positives (Hybrid FP\(=9\).**  
Escalated LLM calls sometimes over-interpret ambiguous logistics or bargaining as circumvention (context over-sensitivity), especially when OCR fragments mix UI chrome with payment-adjacent wording. Because merge uses \(\max\) scoring, a high LLM score can push borderline text into BLOCK even when Tier~1 alone would ALLOW.

**False negatives (Regex FN\(=24\), Hybrid FN\(=21\).**  
Most residual misses are **cascade gaps**: Tier~1 assigns a near-zero score and does not escalate, so the LLM never sees the utterance. Examples include soft Line/account phrasing that OCR fragments or that fall outside keyword coverage. Hybrid recovers only three of these; twenty-one BLOCK gold labels remain undetected.

**Accuracy vs.\ efficiency trade-off.**  
On this corpus, the cascade does **not** dominate regex-only on accuracy (91.0% vs. 92.8%; McNemar \(p=0.1489\)). We adopt it because of **production constraints**—bounded token cost, lower mean latency than LLM-always, and availability under provider failure—not because it wins a significance test.

---

## V. Conclusion and Future Work

We presented a cascaded hybrid moderator for Thai C2C escrow chat and evaluated it on **\(N=333\)** human-labeled OCR utterances from **100** confirmed-fraud screenshots. In live conditions the system does **not** improve accuracy over regex-only (91.0% vs. 92.8%, McNemar \(p=0.1489\)). Its measured contribution is **efficiency**: exiting at Tier~1 for **79.3%** of messages, cutting API calls by **79.3%** and **projected** LLM-token cost by **56.7%**, with mean latency **419.8~ms**. Residual errors are dominated by cascade non-escalation (FN) and LLM over-flagging under a conservative merge (FP).

**Limitations (not claimed as solved).** Single annotator (no IAA); no held-out threshold tuning; OCR noise unmeasured experimentally; utterance-level only; one live LLM pass; screenshots from one anti-scam Facebook/Messenger domain; cost is a projected \$/1M-token proxy; only the \(\max\)/worse-verdict merge is evaluated.

**Future work.**

1. Threshold calibration and cost-sensitive sweeps on a held-out split.
2. Merge ablations and prompt calibration to reduce FP.
3. Second-annotator IAA and OCR-noise robustness tests.
4. Conversation-level evaluation matching the \(\sim\)85% normal-preamble observation.
5. Repeated LLM trials and cross-platform data.

---

## Appendix A — Reproducibility

```bash
# Rebuild OCR dataset (optional; uses Vision API + cache)
php scripts/extract_fraud_screenshot_dataset.php --reuse-ocr=1

# Live benchmark (writes summary + LaTeX tables)
php scripts/run_chat_benchmark.php --mode=live \
  --dataset=dataset/benchmark_dataset.json \
  --out-dir=dataset/benchmark_runs
```

Primary artifacts:

- Dataset: `dataset/benchmark_dataset.json`
- Live summary: `dataset/benchmark_runs/summary_live.json`
- Live tables: `dataset/benchmark_runs/paper_tables_live.tex`
- Service: `includes/ChatModerationService.php`, `includes/ChatModerationTelemetry.php`

---

## Appendix B — Numerical Snapshot (Live Run)

| Metric | Regex-only | Cascaded Hybrid |
|--------|------------|-----------------|
| Accuracy | 92.79% | 90.99% |
| Precision | 1.0000 | 0.8235 |
| Recall | 0.6190 | 0.6667 |
| F1 | 0.7647 | 0.7368 |
| Cohen \(\kappa\) | 0.7249 | 0.6832 |
| TP / TN / FP / FN | 39 / 270 / 0 / 24 | 42 / 261 / 9 / 21 |
| Mean latency | 0.162 ms | 419.833 ms |
| P95 latency | 0.329 ms | 1045.008 ms |
| Est.\ cost (USD) | 0 | 0.020123 |
| McNemar \((b,c)\), \(p\) | — | (3, 9), 0.1489 |

---

*End of manuscript draft.*
