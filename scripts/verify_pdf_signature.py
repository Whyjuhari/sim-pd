#!/usr/bin/env python3
"""Verify embedded PDF signatures and return a small JSON result.

The script deliberately does not inspect visible text or images. It validates
the cryptographic PDF signature container using pyHanko.
"""

from __future__ import annotations

import argparse
import json
import logging
import sys
from pathlib import Path


def emit(payload: dict[str, object], exit_code: int) -> int:
    print(json.dumps(payload, ensure_ascii=False, separators=(",", ":")))
    return exit_code


def enum_name(value: object) -> str | None:
    if value is None:
        return None

    return str(getattr(value, "name", value))


def parse_arguments() -> argparse.Namespace:
    parser = argparse.ArgumentParser(add_help=False)
    parser.add_argument("pdf_path", nargs="?")
    parser.add_argument("--health-check", action="store_true")
    parser.add_argument("--require-trusted", action="store_true")
    parser.add_argument("--allow-fetching", action="store_true")
    parser.add_argument("--trust-root", action="append", default=[])

    return parser.parse_args()


def main() -> int:
    args = parse_arguments()
    logging.disable(logging.CRITICAL)

    try:
        import pyhanko
        from pyhanko.keys import load_cert_from_pemder
        from pyhanko.pdf_utils.reader import PdfFileReader
        from pyhanko.sign.validation import validate_pdf_signature
        from pyhanko_certvalidator import ValidationContext
    except Exception as exception:
        return emit(
            {
                "ok": False,
                "code": "service_unavailable",
                "exception": type(exception).__name__,
                "message": "Dependensi pemeriksa tanda tangan PDF belum tersedia.",
            },
            3,
        )

    if args.health_check:
        return emit(
            {
                "ok": True,
                "code": "ready",
                "version": getattr(pyhanko, "__version__", None),
            },
            0,
        )

    if not args.pdf_path or not Path(args.pdf_path).is_file():
        return emit(
            {"ok": False, "code": "file_unavailable", "message": "PDF tidak tersedia."},
            3,
        )

    try:
        trust_roots = [load_cert_from_pemder(path) for path in args.trust_root]
        validation_context = ValidationContext(
            # An explicit empty list avoids depending on the operating
            # system trust store when only cryptographic integrity is
            # required. Production trust roots are supplied through config.
            trust_roots=trust_roots,
            allow_fetching=args.allow_fetching,
        )

        with open(args.pdf_path, "rb") as pdf_file:
            reader = PdfFileReader(pdf_file)
            signatures = list(reader.embedded_signatures)

            if not signatures:
                return emit(
                    {
                        "ok": False,
                        "code": "no_signature",
                        "signature_count": 0,
                        "message": "PDF tidak memiliki tanda tangan elektronik tertanam.",
                    },
                    2,
                )

            results: list[dict[str, object]] = []
            for embedded_signature in signatures:
                status = validate_pdf_signature(
                    embedded_signature,
                    signer_validation_context=validation_context,
                )
                coverage = enum_name(status.coverage)
                document_permissions_ok = status.docmdp_ok is not False
                cryptographically_valid = bool(
                    status.intact
                    and status.valid
                    and document_permissions_ok
                    and coverage in {"ENTIRE_FILE", "ENTIRE_REVISION"}
                )
                results.append(
                    {
                        "intact": bool(status.intact),
                        "valid": bool(status.valid),
                        "trusted": bool(status.trusted),
                        "coverage": coverage,
                        "modification_level": enum_name(status.modification_level),
                        "docmdp_ok": document_permissions_ok,
                        "cryptographically_valid": cryptographically_valid,
                    }
                )

        if not all(bool(result["cryptographically_valid"]) for result in results):
            return emit(
                {
                    "ok": False,
                    "code": "invalid_signature",
                    "signature_count": len(results),
                    "signatures": results,
                    "message": "Integritas tanda tangan elektronik PDF tidak valid.",
                },
                2,
            )

        if args.require_trusted and not all(bool(result["trusted"]) for result in results):
            return emit(
                {
                    "ok": False,
                    "code": "untrusted_signature",
                    "signature_count": len(results),
                    "signatures": results,
                    "message": "Rantai sertifikat tanda tangan belum dipercaya server.",
                },
                2,
            )

        return emit(
            {
                "ok": True,
                "code": "valid_signature",
                "signature_count": len(results),
                "trusted": all(bool(result["trusted"]) for result in results),
                "signatures": results,
            },
            0,
        )
    except Exception as exception:
        return emit(
            {
                "ok": False,
                "code": "verification_error",
                "exception": type(exception).__name__,
                "message": "Tanda tangan PDF tidak dapat diperiksa.",
            },
            3,
        )


if __name__ == "__main__":
    sys.exit(main())
