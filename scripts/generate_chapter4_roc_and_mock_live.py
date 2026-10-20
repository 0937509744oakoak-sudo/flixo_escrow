# -*- coding: utf-8 -*-
"""Generate ROC/PR for Image Fraud Live + Mock-vs-Live overview figure.

Outputs:
  docs/_ch4_research_figures/fig11_image_fraud_roc_pr.png
  docs/_ch4_research_figures/fig12_mock_vs_live_overview.png
  docs/appendix_ch4/live_curve_stats.json
  updates keys in docs/appendix_ch4/research_stats.json (if present)
"""

from __future__ import annotations

import csv
import json
from pathlib import Path

import matplotlib

matplotlib.use("Agg")
import matplotlib.pyplot as plt
import numpy as np

ROOT = Path(__file__).resolve().parents[1]
APP = ROOT / "docs" / "appendix_ch4"
FIG = ROOT / "docs" / "_ch4_research_figures"
FIG.mkdir(parents=True, exist_ok=True)


def load_image_live() -> tuple[np.ndarray, np.ndarray]:
    path = APP / "image_fraud_real_results.csv"
    if not path.exists():
        path = ROOT / "tests" / "image_fraud_real_results.csv"
    rows = list(csv.DictReader(path.open(encoding="utf-8-sig")))
    y = np.array([int(r["GT Risky (1/0)"]) for r in rows], dtype=int)
    s = np.array([float(r["Risk Score"]) for r in rows], dtype=float)
    return y, s


def roc_pr_points(y_true: np.ndarray, scores: np.ndarray):
    """Threshold sweep (score >= t => positive). Returns dict with curves + AUC."""
    thresholds = sorted({float(x) for x in scores}, reverse=True)
    # also evaluate just below min and above max for full curve ends
    t_list = [max(scores) + 1e-6] + thresholds + [min(scores) - 1e-6]

    fprs, tprs, precs, recs, fnrs = [], [], [], [], []
    rows = []
    P = int((y_true == 1).sum())
    N = int((y_true == 0).sum())
    for t in t_list:
        pred = (scores >= t).astype(int)
        tp = int(((pred == 1) & (y_true == 1)).sum())
        fp = int(((pred == 1) & (y_true == 0)).sum())
        fn = int(((pred == 0) & (y_true == 1)).sum())
        tn = int(((pred == 0) & (y_true == 0)).sum())
        tpr = tp / P if P else 0.0
        fpr = fp / N if N else 0.0
        prec = tp / (tp + fp) if (tp + fp) else 1.0
        rec = tpr
        fnr = fn / P if P else 0.0
        fprs.append(fpr)
        tprs.append(tpr)
        precs.append(prec)
        recs.append(rec)
        fnrs.append(fnr)
        rows.append(
            {
                "threshold": round(float(t), 4),
                "tp": tp,
                "fp": fp,
                "tn": tn,
                "fn": fn,
                "tpr": round(tpr, 4),
                "fpr": round(fpr, 4),
                "precision": round(prec, 4),
                "recall": round(rec, 4),
                "fnr": round(fnr, 4),
            }
        )

    # AUC-ROC via trapezoid on sorted FPR
    order = np.argsort(fprs)
    auc_fn = getattr(np, "trapezoid", None) or getattr(np, "trapz")
    auc_roc = float(auc_fn(np.array(tprs)[order], np.array(fprs)[order]))
    # AUC-PR
    order_pr = np.argsort(recs)
    auc_pr = float(auc_fn(np.array(precs)[order_pr], np.array(recs)[order_pr]))

    # operating points used in system
    ops = {}
    for name, thr in [("flag_threshold", 50.0), ("block_threshold", 75.0)]:
        pred = (scores >= thr).astype(int)
        tp = int(((pred == 1) & (y_true == 1)).sum())
        fp = int(((pred == 1) & (y_true == 0)).sum())
        fn = int(((pred == 0) & (y_true == 1)).sum())
        tn = int(((pred == 0) & (y_true == 0)).sum())
        ops[name] = {
            "threshold": thr,
            "tp": tp,
            "fp": fp,
            "tn": tn,
            "fn": fn,
            "precision": round(tp / (tp + fp), 4) if (tp + fp) else None,
            "recall": round(tp / (tp + fn), 4) if (tp + fn) else None,
            "fpr": round(fp / N, 4) if N else None,
            "tpr": round(tp / P, 4) if P else None,
        }

    return {
        "n": int(len(y_true)),
        "positives": P,
        "negatives": N,
        "auc_roc": round(auc_roc, 4),
        "auc_pr": round(auc_pr, 4),
        "fpr": fprs,
        "tpr": tprs,
        "precision": precs,
        "recall": recs,
        "fnr": fnrs,
        "thresholds": t_list,
        "table": rows,
        "operating_points": ops,
    }


def plot_roc_pr(curve: dict, out: Path):
    fig, axes = plt.subplots(1, 2, figsize=(10.5, 4.4))

    ax = axes[0]
    ax.plot(curve["fpr"], curve["tpr"], color="#2c6e8a", lw=2, label=f"ROC (AUC={curve['auc_roc']:.3f})")
    ax.plot([0, 1], [0, 1], "--", color="#999", lw=1, label="Chance")
    # mark operating points
    for name, op in curve["operating_points"].items():
        if op.get("fpr") is None or op.get("tpr") is None:
            continue
        ax.scatter([op["fpr"]], [op["tpr"]], s=60, zorder=5)
        ax.annotate(
            f"{name.replace('_', ' ')}\n@≥{op['threshold']:.0f}",
            (op["fpr"], op["tpr"]),
            textcoords="offset points",
            xytext=(6, -12),
            fontsize=7,
        )
    ax.set_xlabel("False Positive Rate")
    ax.set_ylabel("True Positive Rate (Recall)")
    ax.set_title("Figure 11a — Image Fraud Live ROC")
    ax.set_xlim(-0.02, 1.02)
    ax.set_ylim(-0.02, 1.02)
    ax.legend(loc="lower right", fontsize=8)

    ax = axes[1]
    ax.plot(curve["recall"], curve["precision"], color="#3d8b6e", lw=2, label=f"PR (AUC={curve['auc_pr']:.3f})")
    baseline = curve["positives"] / max(curve["n"], 1)
    ax.axhline(baseline, color="#999", ls="--", lw=1, label=f"Baseline P={baseline:.2f}")
    for name, op in curve["operating_points"].items():
        if op.get("recall") is None or op.get("precision") is None:
            continue
        ax.scatter([op["recall"]], [op["precision"]], s=60, zorder=5)
        ax.annotate(
            f"@≥{op['threshold']:.0f}",
            (op["recall"], op["precision"]),
            textcoords="offset points",
            xytext=(6, 4),
            fontsize=7,
        )
    ax.set_xlabel("Recall")
    ax.set_ylabel("Precision")
    ax.set_title("Figure 11b — Image Fraud Live Precision–Recall")
    ax.set_xlim(-0.02, 1.02)
    ax.set_ylim(-0.02, 1.05)
    ax.legend(loc="lower left", fontsize=8)

    fig.suptitle("Image Fraud Live (N=58) — Threshold Analysis", fontsize=12, y=1.02)
    fig.tight_layout()
    fig.savefig(out, dpi=160, bbox_inches="tight")
    plt.close(fig)


def plot_mock_vs_live(out: Path):
    # Acc % — Live where available
    modules = ["eKYC", "Chat", "Image Fraud", "Dispute", "Sumsub"]
    mock = [100.0, 97.0, 100.0, 100.0, 100.0]
    live = [100.0, 97.0, 63.79, None, None]
    live_n = ["N=4", "N=100", "N=58", "—", "—"]
    notes = ["pairs", "LLM", "EXIF+Vision", "no expert GT", "no live"]

    # load live summaries if present
    ek = APP / "ekyc_real_summary.json"
    ch = APP / "chat_real_summary.json"
    im = APP / "image_fraud_real_summary.json"
    if ek.exists():
        live[0] = float(json.loads(ek.read_text(encoding="utf-8")).get("accuracy_all_pairs_pct", 100))
    if ch.exists():
        live[1] = float(json.loads(ch.read_text(encoding="utf-8")).get("accuracy_pct", 97))
    if im.exists():
        live[2] = float(json.loads(im.read_text(encoding="utf-8")).get("accuracy_pct", 63.79))

    x = np.arange(len(modules))
    w = 0.36
    fig, ax = plt.subplots(figsize=(10.2, 4.8))
    bars_m = ax.bar(x - w / 2, mock, w, label="Mock / Controlled", color="#8aa4b5")
    live_plot = [v if v is not None else 0 for v in live]
    bars_l = ax.bar(x + w / 2, live_plot, w, label="Live / Real evidence", color="#2c6e8a")
    for i, v in enumerate(live):
        if v is None:
            bars_l[i].set_facecolor("#d0d0d0")
            bars_l[i].set_hatch("//")
            bars_l[i].set_height(8)
            ax.text(x[i] + w / 2, 10, "no live", ha="center", va="bottom", fontsize=8, color="#555")
        else:
            ax.text(x[i] + w / 2, v + 1.2, f"{v:.1f}%\n{live_n[i]}", ha="center", va="bottom", fontsize=8)
        ax.text(x[i] - w / 2, mock[i] + 1.2, f"{mock[i]:.0f}%", ha="center", va="bottom", fontsize=8)

    ax.set_xticks(x)
    ax.set_xticklabels([f"{m}\n({notes[i]})" for i, m in enumerate(modules)])
    ax.set_ylim(0, 120)
    ax.set_ylabel("Accuracy / Agree (%)")
    ax.set_title("Figure 12 — Mock vs Live Overview (same chapter modules)")
    ax.legend(loc="upper right")
    ax.axhline(100, color="#ccc", lw=0.8)
    fig.text(
        0.01,
        0.01,
        "Hatched = no Live eval yet (Dispute expert labels / Sumsub live pipeline). "
        "eKYC Live N=4 is illustrative only.",
        fontsize=7,
        color="#555",
    )
    fig.tight_layout(rect=[0, 0.04, 1, 1])
    fig.savefig(out, dpi=160, bbox_inches="tight")
    plt.close(fig)

    return {
        "modules": modules,
        "mock_acc_pct": mock,
        "live_acc_pct": live,
        "live_n": live_n,
        "notes": notes,
    }


def main():
    y, s = load_image_live()
    curve = roc_pr_points(y, s)
    # drop bulky arrays from json export of table only
    curve_export = {
        k: curve[k]
        for k in ("n", "positives", "negatives", "auc_roc", "auc_pr", "operating_points", "table")
    }

    fig11 = FIG / "fig11_image_fraud_roc_pr.png"
    plot_roc_pr(curve, fig11)

    fig12 = FIG / "fig12_mock_vs_live_overview.png"
    overview = plot_mock_vs_live(fig12)

    out = {
        "image_fraud_live_curves": curve_export,
        "mock_vs_live": overview,
        "figures": [
            str(fig11.relative_to(ROOT)).replace("\\", "/"),
            str(fig12.relative_to(ROOT)).replace("\\", "/"),
        ],
        "gaps": [
            "eKYC Live still small (N=4) — expand genuine/impostor pairs",
            "Dispute AI has no real-case / expert-label evaluation yet",
            "ROC/DET not yet built for Chat/eKYC (need score calibration sweeps on larger live sets)",
        ],
    }
    (APP / "live_curve_stats.json").write_text(
        json.dumps(out, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
    )

    rs_path = APP / "research_stats.json"
    if rs_path.exists():
        rs = json.loads(rs_path.read_text(encoding="utf-8"))
        rs["live_curves"] = out
        figs = rs.get("figures") or []
        for f in out["figures"]:
            if f not in figs:
                figs.append(f)
        rs["figures"] = figs
        rs_path.write_text(json.dumps(rs, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    print(f"Wrote {fig11}")
    print(f"Wrote {fig12}")
    print(f"AUC-ROC={curve['auc_roc']} AUC-PR={curve['auc_pr']}")
    print(f"Wrote {APP / 'live_curve_stats.json'}")


if __name__ == "__main__":
    main()
