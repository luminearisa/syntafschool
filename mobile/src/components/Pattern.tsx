import React, { useMemo } from 'react';
import Svg, { Path } from 'react-native-svg';

/**
 * Motif bintang segi-delapan (dua persegi bertumpuk, diputar 45°) berulang —
 * garis tipis, dipakai sebagai tekstur latar header & kartu.
 */
function starPath(cx: number, cy: number, r: number): string {
  const sq = (rot: number) => {
    const pts = [0, 1, 2, 3].map((i) => {
      const a = (Math.PI / 2) * i + rot;
      return `${(cx + r * Math.cos(a)).toFixed(1)} ${(cy + r * Math.sin(a)).toFixed(1)}`;
    });
    return `M${pts[0]} L${pts[1]} L${pts[2]} L${pts[3]} Z`;
  };
  const inner = r * 0.46;
  const ring = `M${cx - inner} ${cy} A${inner} ${inner} 0 1 0 ${cx + inner} ${cy} A${inner} ${inner} 0 1 0 ${cx - inner} ${cy}`;
  return `${sq(Math.PI / 4)} ${sq(0)} ${ring}`;
}

interface Props {
  width: number;
  height: number;
  color?: string;
  opacity?: number;
  size?: number;
}

export function IslamicPattern({ width, height, color = '#FFFFFF', opacity = 0.1, size = 44 }: Props) {
  const d = useMemo(() => {
    const parts: string[] = [];
    const cols = Math.ceil(width / size) + 1;
    const rows = Math.ceil(height / size) + 1;
    for (let r = 0; r < rows; r++) {
      for (let c = 0; c < cols; c++) {
        parts.push(starPath(c * size + (r % 2 ? size / 2 : 0), r * (size * 0.5), size * 0.36));
      }
    }
    return parts.join(' ');
  }, [width, height, size]);

  return (
    <Svg width={width} height={height} style={{ position: 'absolute', left: 0, top: 0 }} pointerEvents="none">
      <Path d={d} stroke={color} strokeWidth={1} fill="none" opacity={opacity} />
    </Svg>
  );
}
