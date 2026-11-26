/** The mark of Hornet: a cell of the hive, in the gradient of Pollora, with the sting of a shield. */
export default function Mark() {
	return (
		<svg className="hornet-mark" viewBox="0 0 32 32" width="36" height="36" aria-hidden="true">
			<defs>
				<linearGradient id="hornet-mark-g" x1="0" y1="0" x2="1" y2="1">
					<stop offset="0" stopColor="#fb196d" />
					<stop offset="1" stopColor="#ff8c12" />
				</linearGradient>
			</defs>
			<path d="M16 1l13 7.5v15L16 31 3 23.5v-15z" fill="url(#hornet-mark-g)" />
			<path d="M10 11h12l-6 13z" fill="#1d142a" />
			<path d="M12.5 14.2h7M14 17.4h4" stroke="url(#hornet-mark-g)" strokeWidth="1.4" />
		</svg>
	);
}
