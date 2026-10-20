# Figure 2 — Cascaded Hybrid Moderation Architecture

**Purpose:** System pipeline diagram for the paper (Fig.~2 / architecture figure).  
**Usage:** Paste the Mermaid block into [Mermaid Live Editor](https://mermaid.live), export PNG/SVG/PDF, then include in Overleaf as `fig2_cascade.pdf`.  
**Related LaTeX:** `docs/paper_main.tex` → `\label{fig:architecture}`.

---

## Mermaid flowchart (copy-paste ready)

```mermaid
flowchart TD
  IN["Input: Thai C2C chat message x"] --> T1

  subgraph TIER1["Tier 1 — Local Regex / Heuristics"]
    T1["Normalize text<br/>score phones, bank IDs, PromptPay,<br/>Line/off-platform keywords"]
    T1 --> D1{"Escalate?<br/>score mid-range OR digit-run<br/>OR soft cues (proposed)"}
  end

  D1 -->|"No<br/>(clear / high-confidence / AI off)"| OUT1["Output via Tier 1<br/>provider = none"]
  D1 -->|"Yes"| T2

  subgraph TIER2["Tier 2 — Cloud LLM Multi-Provider Escalation"]
    T2["Build moderation prompt<br/>+ regex hints + soft cues"]
    T2 --> G{"Groq"}
    G -->|"OK"| MERGE["merge(regex, llm)<br/>max score + worse verdict"]
    G -->|"Fail"| OR{"OpenRouter"}
    OR -->|"OK"| MERGE
    OR -->|"Fail"| GM{"Gemini"}
    GM -->|"OK"| MERGE
    GM -->|"All fail"| T3
  end

  MERGE --> OUT2["Output via Tier 2<br/>provider = groq | openrouter | gemini"]

  subgraph TIER3["Tier 3 — Graceful Fallback"]
    T3["Return Tier-1 decision<br/>optional pass → flag<br/>on digit-run OR soft cues"]
  end

  T3 --> OUT3["Output via Tier 3<br/>provider = heuristic"]

  OUT1 --> MAP["Map production verdict"]
  OUT2 --> MAP
  OUT3 --> MAP

  MAP --> P["pass → ALLOW"]
  MAP --> F["flag → BLOCK (review)"]
  MAP --> R["reject → BLOCK (hard block)"]

  classDef tier1 fill:#e8f5e9,stroke:#2e7d32,color:#111
  classDef tier2 fill:#e3f2fd,stroke:#1565c0,color:#111
  classDef tier3 fill:#fff3e0,stroke:#ef6c00,color:#111
  classDef io fill:#f5f5f5,stroke:#616161,color:#111
  classDef out fill:#fce4ec,stroke:#c2185b,color:#111

  class T1,D1 tier1
  class T2,G,OR,GM,MERGE tier2
  class T3 tier3
  class IN,MAP io
  class OUT1,OUT2,OUT3,P,F,R out
```

---

## Compact variant (single-column paper width)

```mermaid
flowchart TB
  A[Chat message] --> B[Tier 1: Regex / Heuristics]
  B -->|decisive / no escalate| Z[Verdict: pass / flag / reject]
  B -->|mid-score escalate| C[Tier 2a: Groq]
  C -->|fail| D[Tier 2b: OpenRouter]
  D -->|fail| E[Tier 2c: Gemini]
  C -->|ok| M[Merge max score]
  D -->|ok| M
  E -->|ok| M
  E -->|all fail| F[Tier 3: Fallback Heuristic]
  M --> Z
  F --> Z
  Z --> O[ALLOW or BLOCK]
```

---

## Figure caption (English, camera-ready)

**Fig. 2.** Cascaded hybrid moderation architecture for Thai C2C escrow chat. Incoming messages are scored by Tier~1 local regex/heuristics. Ambiguous mid-score cases escalate to Tier~2 cloud LLMs with multi-provider failover (Groq → OpenRouter → Gemini). If all providers fail, Tier~3 returns a graceful regex-based fallback. Final production verdicts (`pass` / `flag` / `reject`) map to evaluation labels \textsc{allow}/\textsc{block}.

---

## Thai caption (สำหรับสไลด์)

**รูปที่ 2** สถาปัตยกรรม Cascaded Hybrid 3 ชั้น: Tier 1 Regex → Tier 2 LLM (Groq / OpenRouter / Gemini) → Tier 3 Fallback → ผลลัพธ์ pass / flag / reject

---

## Export checklist for Overleaf

1. Open https://mermaid.live and paste the first diagram.  
2. Export **PNG** (300 dpi) or **SVG**, then convert to PDF if preferred.  
3. Upload to Overleaf project as `fig2_cascade.pdf` (or `.png`).  
4. In `paper_main.tex`, replace the `\fbox{...}` placeholder under `\label{fig:architecture}` with:

```latex
\includegraphics[width=\columnwidth]{fig2_cascade.pdf}
```
