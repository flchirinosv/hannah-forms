import sys
sys.path.insert(0, 'tests/browser')
import vf

R = vf.R()
motivo = 'SMTP no configurado (MAIL_HOST y MAIL_FROM_ADDRESS vacíos): el bloque L se marca N/A según la nota del documento'
for i in range(1, 8):
    R.na(f'L{i}', motivo)
