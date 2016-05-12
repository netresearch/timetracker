all: clean doc

clean:
		rm -rf app/cache/dev/*
		rm -f doc/*.pdf

doc: doc/README.pdf doc/Benutzerdokumentation.pdf doc/Entwicklerdokumentation.pdf doc/ChangeLog.pdf

doc/README.pdf: doc/README.rst doc/netresearch.style
		rst2pdf -b 1 -o doc/README.pdf -s doc/netresearch.style doc/README.rst

doc/Benutzerdokumentation.pdf: doc/Benutzerdokumentation.rst doc/netresearch.style
		rst2pdf -b 1 -o doc/Benutzerdokumentation.pdf -s doc/netresearch.style doc/Benutzerdokumentation.rst

doc/Entwicklerdokumentation.pdf: doc/Entwicklerdokumentation.rst doc/netresearch.style
		rst2pdf -b 1 -o doc/Entwicklerdokumentation.pdf -s doc/netresearch.style doc/Entwicklerdokumentation.rst

doc/ChangeLog.pdf: doc/ChangeLog.rst doc/netresearch.style
		rst2pdf -b 1 -o doc/ChangeLog.pdf -s doc/netresearch.style doc/ChangeLog.rst

.PHONY: doc
